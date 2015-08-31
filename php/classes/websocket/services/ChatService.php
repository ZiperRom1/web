<?php
/**
 * Chat services to manage a chat with a WebSocket server
 *
 * @category WebSocket service
 * @author   Romain Laneuville <romain.laneuville@hotmail.fr>
 */

namespace classes\websocket\services;

use \classes\websocket\Server as Server;
use \interfaces\ServiceInterface as Service;
use \classes\IniManager as Ini;
use \classes\entities\User as User;
use \classes\entitiesManager\UserEntityManager as UserEntityManager;

/**
 * Chat services to manage a chat with a WebSocket server
 *
 * @todo Sonar this class
 * @todo Bug with USE_INCLUDE_PATH of the file_get_contents() function, see method getRoomsName() FIX WITH stream_resolve_include_path
 * @class ChatService
 */
class ChatService extends Server implements Service
{
    use \traits\ShortcutsTrait;

    /**
     * @var string[] $users All the user rooms where he is connected to indexed by his socketHash
     */
    private $usersRooms = array();
    /**
     * @var User[] $usersRegistered All the authenticated User object connected indexed by their socketHash
     */
    private $usersRegistered = array();
    /**
     * @var string[] $usersGuest All the guest users pseudonyms connected indexed by their socketHash
     */
    private $usersGuest = array();
    /**
     * @var string $serverAddress The server adress to connect
     */
    private $serverAddress;
    /**
     * @var string $chatService The chat service name
     */
    private $chatService;
    /**
     * @var string $savingDir The absolute path from the lib path where conversations will be stored
     */
    private $savingDir;
    /**
     * @var integer $maxMessagesPerFile The maximum number of messages per file saved
     */
    private $maxMessagesPerFile;
    /**
     * @var string[] $roomsName Array containing all the rooms name that exists
     */
    private $roomsName;
    /**
     * @var string $roomsNamePath The path of the file storing the list of rooms name
     */
    private $roomsNamePath;
    /**
     * @var array $rooms Rooms live sessions
     *
     * array(
     *     'room name' => array(
     *         'sockets'      => array(socketHash1 => socket, socketHash2 => socket, ...),
     *         'pseudonyms'   => array(socketHash1 => pseudonym1, socketHash2 => pseudonym2, ...)
     *         'creator'      => User object instance,
     *         'type'         => 'public' || 'private',
     *         'password'     => 'password',
     *         'creationDate' => DateTime object instance,
     *         'maxUsers'     => integer,
     *         'historicPart' => integer,
     *         'historic'     => array(
     *             array(
     *                 'part'    => the part number,
     *                 'message' => the text message,
     *                 'time'    => the message sent time,
     *                 'from'    => the pseudonym of the message owner,
     *                 'to'      => the pseudonym of the message reciever or 'all'
     *             )
     *         )
     *     )
     * )
     */
    private $rooms = array();

    /*=====================================
    =            Magic methods            =
    =====================================*/
    
    /**
     * Constructor that sets the WebSocket server adress and create en empty default room
     *
     * @param string $serverAddress The WebSocket server adress
     */
    public function __construct($serverAddress)
    {
        Ini::setIniFileName(Ini::INI_CONF_FILE);
        $params                   = Ini::getSectionParams('Chat service');
        $this->serverKey          = Ini::getParam('Socket', 'serverKey');
        $this->serverAddress      = $serverAddress;
        $this->chatService        = $params['serviceName'];
        $this->savingDir          = $params['savingDir'];
        $this->maxMessagesPerFile = $params['maxMessagesPerFile'];
        $this->roomsNamePath      = $this->savingDir . DIRECTORY_SEPARATOR . 'rooms_name';
        $this->roomsName          = $this->getRoomsName();

        // Create the default room
        // todo check the loading of the default room
        $this->rooms['default'] = array(
            'sockets'      => array(),
            'pseudonyms'   => array(),
            'type'         => 'public',
            'password'     => '',
            'creationDate' => new \DateTime(),
            'maxUsers'     => $params['maxUsers'],
            'historicPart' => $this->getLastPartNumber('default'),
            'historic'     => array('part' => 0)
        );

        $this->loadConversation('default', $this->rooms['default']['historicPart']);
    }
    
    /*=====  End of Magic methods  ======*/

    /*======================================
    =            Public methods            =
    ======================================*/
    
    /**
     * Method to recieves data from the WebSocket server
     *
     * @param resource $socket The client socket
     * @param array    $data   JSON decoded client data
     */
    public function service($socket, $data)
    {
        switch ($data['action']) {
            case $this->serverKey . 'disconnect':
                // Action called by the server
                $this->disconnectUser($data['clientSocket']);

                break;

            case 'sendMessage':
                $this->sendMessage($socket, $data);

                break;

            case 'connect':
                $this->connectUser($socket, $data);

                break;

            case 'disconnect':
                $this->disconnectUser($socket);
                
                break;

            case 'createRoom':
                $this->createRoom($socket, $data);

                break;

            default:
                $this->send($socket, $this->encode(json_encode(array(
                    'service' => $this->chatService,
                    'success' => false,
                    'text'    => _('Unknown action')
                ))));
        }
    }
    
    /*=====  End of Public methods  ======*/

    /*=======================================
    =            Private methods            =
    =======================================*/
    
    /**
     * Create a chat room by an authenticated user request
     *
     * @param resource $socket The user socket
     * @param array    $data   JSON decoded client data
     */
    private function createRoom($socket, $data)
    {
        $success = false;
        @$this->setIfIsSet($password, $data['password'], null);
        @$this->setIfIsSet($roomPassword, $data['roomPassword'], null);
        @$this->setIfIsSetAndTrim($roomName, $data['roomName'], null);
        @$this->setIfIsSetAndTrim($login, $data['login'], null);
        @$this->setIfIsSetAndTrim($type, $data['type'], null);
        @$this->setIfIsSetAndTrim($maxUsers, $data['maxUsers'], null);

        if ($roomName === null || $roomName === '') {
            $message = _('The room name is required');
        } elseif (in_array($roomName, $this->roomsName)) {
            $message = sprintf(_('The chat room name "%s" already exists'), $roomName);
        } elseif ($type !== 'public' && $type !== 'private') {
            $message = _('The room type must be "public" or "private"');
        } elseif ($type === 'private' && ($password === null || strlen($password) === 0)) {
            $message = _('The password is required and must not be empty');
        } elseif (!is_numeric($maxUsers) || $maxUsers < 2) {
            $message = _('The max number of users must be a number and must no be less than 2');
        } else {
            $userEntityManager = new UserEntityManager();
            $user              = $userEntityManager->authenticateUser($login, $password);

            if ($user === false) {
                $message = _('Authentication failed');
            } else {
                $userEntityManager->setEntity($user);

                $socketHash             = $this->getClientName($socket);
                $pseudonym              = $userEntityManager->getPseudonymForChat();
                $this->roomsName[]      = $roomName;
                $this->rooms[$roomName] = array(
                    'sockets'      => array($socketHash => $socket),
                    'pseudonyms'   => array($socketHash => $pseudonym),
                    'creator'      => $user,
                    'type'         => $type,
                    'password'     => $roomPassword,
                    'creationDate' => new \DateTime(),
                    'maxUsers'     => $maxUsers,
                    'historicPart' => 0,
                    'historic'     => array('part' => 0)
                );

                mkdir(stream_resolve_include_path($this->savingDir) . DIRECTORY_SEPARATOR . $roomName);
                $this->addUserRoom($socketHash, $roomName);
                $this->setRoomsName();
                $this->setLastPartNumber($roomName, 0);

                $success = true;
                $message = sprintf(_('The chat room name "%s" is successfully created !'), $roomName);
                $this->log(sprintf(
                    _('[chatService] New room added "%s" (%s) maxUsers = %s and password = "%s" by %s'),
                    $roomName,
                    $type,
                    $maxUsers,
                    $roomPassword,
                    $pseudonym
                ));
            }
        }

        $this->send($socket, $this->encode(json_encode(array(
            'service'  => $this->chatService,
            'action'   => 'createRoom',
            'success'  => $success,
            'roomName' => $roomName,
            'type'     => $type,
            'maxUsers' => $maxUsers,
            'password' => $roomPassword,
            'text'     => $message
        ))));
    }

    /**
     * Connect a user to one chat room as a registered or a guest user
     *
     * @param resource $socket The user socket
     * @param array    $data   JSON decoded client data
     */
    private function connectUser($socket, $data)
    {
        $success   = false;
        $response  = array();
        @$this->setIfIsSet($password, $data['user']['password'], null);
        @$this->setIfIsSet($roomPassword, $data['password'], null);
        @$this->setIfIsSetAndTrim($roomName, $data['roomName'], null);
        @$this->setIfIsSetAndTrim($email, $data['user']['email'], null);
        @$this->setIfIsSetAndTrim($pseudonym, $data['pseudonym'], null);

        // Default room if no room defined
        if ($roomName === null || $roomName === '') {
            $roomName = 'default';
        }

        if (!in_array($roomName, $this->roomsName)) {
            $message = sprintf(_('The chat room "%s" does not exist'), $roomName);
        } else {
            if (!isset($this->rooms[$roomName])) {
                // Load the room if it is not in cache
                $this->loadRoom($roomName);
            }

            if (count($this->rooms[$roomName]['sockets']) >= $this->rooms[$roomName]['maxUsers']) {
                $message = _('The room is full');
            } else {
                $message    = sprintf(_('You\'re connected to the chat room "%s" !'), $roomName);
                $socketHash = $this->getClientName($socket);

                if ($email !== null && $password !== null) {
                    // Authenticated user
                    $userEntityManager = new UserEntityManager();
                    $user              = $userEntityManager->authenticateUser($email, $password);

                    $userEntityManager->setEntity($user);

                    if ($user !== false) {
                        // check if room is private
                        if (!$this->checkPrivateRoomPassword($roomName, $roomPassword)) {
                            $message = _('You cannot access to this room or the password is incorrect');
                        } else {
                            $pseudonym                          = $userEntityManager->getPseudonymForChat();
                            $this->usersRegistered[$socketHash] = $user;
                            $success                            = true;
                        }
                    } else {
                        $message = _('The authentication failed');
                    }
                } elseif ($pseudonym !== null) {
                    // Guest user
                    if ($pseudonym === '') {
                        $message = _('The pseudonym can\'t be empty');
                    } elseif (!$this->pseudonymIsInRoom($pseudonym, $roomName)) {
                         // check if room is private
                        if (!$this->checkPrivateRoomPassword($roomName, $roomPassword)) {
                            $message = _('You cannot access to this room or the password is incorrect');
                        } else {
                            $this->usersGuest[$socketHash] = $pseudonym;
                            $success                       = true;
                        }
                    } else {
                        $message = sprintf(_('The pseudonym "%s" is already used'), $pseudonym);
                    }
                } else {
                    $message = _('You must enter a pseudonym');
                }

                if ($success) {
                    // Add user to the room
                    $this->rooms[$roomName]['sockets'][$socketHash]    = $socket;
                    $this->rooms[$roomName]['pseudonyms'][$socketHash] = $pseudonym;

                    $this->addUserRoom($socketHash, $roomName);

                    $this->log(_(
                        '[chatService] New user added with the pseudonym "' . $pseudonym . '" in the room "'
                        . $roomName . '"'
                    ));

                    $response['roomName'] = $roomName;
                    $response['type']     = $this->rooms[$roomName]['type'];
                    $response['maxUsers'] = $this->rooms[$roomName]['maxUsers'];
                    $response['password'] = $this->rooms[$roomName]['password'];
                }
            }
        }

        $response = array_merge($response, array(
                'service' => $this->chatService,
                'action'  => 'connect',
                'success' => $success,
                'text'    => $message
        ));

        $this->send($socket, $this->encode(json_encode($response)));
    }

    /**
     * Send a public message to all the users in the room or a private message to one user in the room
     *
     * @param resource $socket The user socket
     * @param array    $data   JSON decoded client data
     */
    private function sendMessage($socket, $data)
    {
        $success    = false;
        $message    = _('Message successfully sent !');
        $socketHash = $this->getClientName($socket);
        @$this->setIfIsSet($password, $data['password'], null);
        @$this->setIfIsSetAndTrim($roomName, $data['roomName'], null);
        @$this->setIfIsSetAndTrim($recievers, $data['recievers'], null);
        @$this->setIfIsSetAndTrim($text, $data['message'], null);

        if ($text === null || $text === '') {
            $message = _('The message cannot be empty');
        } elseif ($roomName === null) {
            $message = _('The chat room name cannot be empty');
        } elseif ($this->rooms[$roomName]['type'] === 'private' && $password !== $this->rooms[$roomName]['password']) {
            $message = _('Incorrect password');
        } elseif (!array_key_exists($socketHash, $this->rooms[$roomName]['sockets'])) {
            $message = sprintf(_('You are not connected to the room %s'), $roomName);
        } elseif ($recievers === null) {
            $message = _('You must precise a reciever for your message (all or a pseudonym)');
        } elseif ($recievers !== 'all' && !$this->pseudonymIsInRoom($recievers, $roomName)) {
            $message = sprintf(_('The user "%" is not connected to the room "%"'), $recievers, $roomName);
        } else {
            $now       = date('Y-m-d H:i:s');
            $pseudonym = $this->rooms[$roomName]['pseudonyms'][$socketHash];

            if ($recievers === 'all') {
                // Send the message to all the users in the chat room
                foreach ($this->rooms[$roomName]['sockets'] as $userSocket) {
                    $this->sendMessageToUser($socket, $userSocket, $text, $roomName, 'public', $now);
                }
            } else {
                // Send the message to one user
                $recieverHash   = array_search($recievers, $this->rooms[$roomName]['pseudonyms']);
                $recieverSocket = $this->rooms[$roomName]['sockets'][$recieverHash];

                $this->sendMessageToUser($socket, $recieverSocket, $text, $roomName, 'private', $now);
            }

            $this->log(sprintf(
                _('[chatService] Message "%s" sent by "%s" to "%s" in the room "%s"'),
                $text,
                $pseudonym,
                $recievers,
                $roomName
            ));

            $this->updateConversation($roomName, $now, $text, $pseudonym, $recievers);
            $success = true;
        }

        $this->send($socket, $this->encode(json_encode(array(
            'service' => $this->chatService,
            'action'  => 'sendMessage',
            'success' => $success,
            'text'    => $message
        ))));
    }

    /**
     * Disconnet a user from all the chat he was connected to
     *
     * @param resource $socket The user socket
     */
    private function disconnectUser($socket)
    {
        $socketHash = $this->getClientName($socket);

        foreach ($this->usersRooms[$socketHash] as $roomName) {
            unset($this->rooms[$roomName]['sockets'][$socketHash]);
            unset($this->rooms[$roomName]['pseudonyms'][$socketHash]);

            // Save and close the chat room if noone is in
            if (count($this->rooms[$roomName]['sockets']) === 0) {
                $this->saveRoom($roomName);
                $this->saveConversation($roomName);
                unset($this->rooms[$roomName]);
            }
        }
    }

    /**
     * Check if a user has the right to enter a private room
     *
     * @param  string  $roomName     The room name
     * @param  string  $roomPassword The room password the user sent
     * @return boolean               True if the user have the right to enter the room else false
     */
    private function checkPrivateRoomPassword($roomName, $roomPassword)
    {
        if ($this->rooms[$roomName]['type'] === 'private' && $this->rooms[$roomName]['password'] !== $roomPassword) {
            $authorized = false;
        } else {
            $authorized = true;
        }

        return $authorized;
    }

    /**
     * Check if a pseudonym is already used in the defined room
     *
     * @param  string  $pseudonym The pseudonym
     * @param  string  $roomName  The room name to connect to
     * @return boolean            True if the pseudonym exists in the room else false
     */
    private function pseudonymIsInRoom($pseudonym, $roomName)
    {
        return in_array($pseudonym, $this->rooms[$roomName]['pseudonyms']);
    }

    /**
     * Add a room to the user when he is connected to this room
     *
     * @param string $socketHash The user socket hash
     * @param string $roomName   The room name
     */
    private function addUserRoom($socketHash, $roomName)
    {
        if (!isset($this->usersRooms[$socketHash])) {
            $this->usersRooms[$socketHash] = array();
        }

        $this->usersRooms[$socketHash][] = $roomName;
    }

    /**
     * Send a message to a user
     *
     * @param resource $socketFrom The user socket to send the message from
     * @param resource $socketTo   The user socket to send the message to
     * @param string   $message    The text message
     * @param string   $roomName   The room name
     * @param string   $type       The message type ('public' || 'private')
     * @param string   $date       The server date at the moment the message was processed (Y-m-d H:i:s)
     */
    private function sendMessageToUser($socketFrom, $socketTo, $message, $roomName, $type, $date)
    {
        $this->send($socketTo, $this->encode(json_encode(array(
            'service'   => $this->chatService,
            'action'    => 'recieveMessage',
            'pseudonym' => $this->rooms[$roomName]['pseudonyms'][$this->getClientName($socketFrom)],
            'time'      => $date,
            'roomName'  => $roomName,
            'type'      => $type,
            'text'      => $message
        ))));
    }

    /**
     * Store a room in a file to recover it later
     *
     * @param string $roomName The room name
     */
    private function saveRoom($roomName)
    {
        $tmpHistoric                        = $this->rooms[$roomName]['historic'];
        $this->rooms[$roomName]['historic'] = array();

        file_put_contents(
            stream_resolve_include_path($this->savingDir . DIRECTORY_SEPARATOR . $roomName) .
            DIRECTORY_SEPARATOR . 'room.json',
            json_encode($this->rooms[$roomName])
        );

        $this->rooms[$roomName]['historic'] = $tmpHistoric;
    }

    /**
     * Load a room that was stored in a file
     *
     * @param string $roomName The room name
     */
    private function loadRoom($roomName)
    {
        $this->rooms[$roomName] = json_decode(file_get_contents(stream_resolve_include_path(
            $this->savingDir . DIRECTORY_SEPARATOR . $roomName . DIRECTORY_SEPARATOR . 'room.json'
        )), true);

        $this->loadConversation($roomName, $this->getLastPartNumber($roomName));
    }

    /**
     * Update a conversation with a new message
     *
     * @param  string $roomName The room name
     * @param  string $time     The server message sent time
     * @param  string $message  The text message
     * @param  string $from     The pseudonym of the user message owner
     * @param  string $to       The pseudonym of the user message reviever or 'all'
     */
    private function updateConversation($roomName, $time, $message, $from, $to)
    {
        if (count($this->rooms[$roomName]['historic']) >= $this->maxMessagesPerFile) {
            $this->saveConversation($roomName);
            $this->rooms[$roomName]['historic'] = array();
            $this->setLastPartNumber($roomName, ++$this->rooms[$roomName]['historicPart']);
        }

        $this->rooms[$roomName]['historic'][] = array(
            'part'    => $this->rooms[$roomName]['historicPart'],
            'message' => $message,
            'time'    => $time,
            'from'    => $from,
            'to'      => $to
        );
    }

    /**
     * Store the conversation into a text file
     *
     * @param string $roomName The room name
     */
    private function saveConversation($roomName)
    {
        $part = $this->rooms[$roomName]['historic']['part'];

        file_put_contents(
            stream_resolve_include_path($this->savingDir . DIRECTORY_SEPARATOR . $roomName) .
            DIRECTORY_SEPARATOR . 'historic-part-' . $part . '.json',
            json_encode($this->rooms[$roomName]['historic'])
        );
    }

    /**
     * Load a conversation
     *
     * @param string  $roomName The room name
     * @param integer $part     The conversation part
     */
    private function loadConversation($roomName, $part)
    {
        $conversation = @file_get_contents(stream_resolve_include_path(
            $this->savingDir . DIRECTORY_SEPARATOR . $roomName .
            DIRECTORY_SEPARATOR . 'historic-part-' . $part . '.json'
        ));

        if ($conversation === false) {
            $conversation = array();
        } else {
            $conversation = json_decode($conversation, true);
        }

        $this->rooms[$roomName]['historic'] = $conversation;
    }

    /**
     * Get the last part number of room historic
     *
     * @param  string  $roomName The room name
     * @return integer           The last part number
     */
    private function getLastPartNumber($roomName)
    {
        return (int) file_get_contents(
            stream_resolve_include_path($this->savingDir . DIRECTORY_SEPARATOR . $roomName) .
            DIRECTORY_SEPARATOR . 'historic-last-part'
        );
    }

    /**
     * Set the last part number of room historic
     *
     * @param string  $roomName The room name
     * @param integer $part     The last part number
     */
    private function setLastPartNumber($roomName, $part)
    {
        file_put_contents(
            stream_resolve_include_path($this->savingDir . DIRECTORY_SEPARATOR . $roomName) .
            DIRECTORY_SEPARATOR . 'historic-last-part',
            $part
        );
    }

    /**
     * Get the rooms name
     *
     * @return string[] The rooms name
     */
    private function getRoomsName()
    {
        // PHP FILE_USE_INCLUDE_PATH is bugged
        // return json_decode(file_get_contents($this->roomsNamePath), FILE_USE_INCLUDE_PATH);
        return json_decode(file_get_contents($this->roomsNamePath, FILE_USE_INCLUDE_PATH), true);
    }

    /**
     * Update the rooms name
     */
    private function setRoomsName()
    {
        file_put_contents($this->roomsNamePath, json_encode($this->roomsName), FILE_USE_INCLUDE_PATH);
    }

    /**
     * Log a message to the server if verbose mode is activated
     *
     * @param string $message The message to output
     */
    private function log($message)
    {
        $serverSocket = stream_socket_client($this->serverAddress);
        Ini::setIniFileName(Ini::INI_CONF_FILE);
        $this->send($serverSocket, Ini::getParam('Socket', 'serviceKey') . $message);
        fclose($serverSocket);
    }
    
    /*=====  End of Private methods  ======*/
}
