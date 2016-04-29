<?php
/**
 * Entity manager for the entity ChatRoom
 *
 * @package    EntityManager
 * @author     Romain Laneuville <romain.laneuville@hotmail.fr>
 */

namespace classes\entitiesManager;

use \abstracts\EntityManager as EntityManager;
use \classes\entities\ChatRoom as ChatRoom;
use \classes\entitiesCollection\ChatRoomCollection as ChatRoomCollection;
use \classes\DataBase as DB;

/**
 * Performed database action relative to the chat room entity class
 *
 * @property   ChatRoom     $entity     The ChatRoom entity
 *
 * @method ChatRoom getEntity() {
 *      Get the chat room entity
 *
 *      @return ChatRoom The chat room entity
 * }
 */
class ChatRoomEntityManager extends EntityManager
{
    /**
     * Constructor that can take a ChatRoom entity as first parameter
     *
     * @param      ChatRoom  $entity  A ChatRoom entity object DEFAULT null
     */
    public function __construct(ChatRoom $entity = null)
    {
        parent::__construct($entity);

        if ($entity === null) {
            $this->entity = new ChatRoom();
        } elseif ($entity->getChatRoomBanCollection() !== null) {
            $this->entity->setChatRoomBanCollection($entity->getChatRoomBanCollection());
        }
    }

    /**
     * Get all the rooms in the database
     *
     * @return     ChatRoomCollection  All the rooms in the database
     */
    public function getAllRooms(): ChatRoomCollection
    {
        $rooms    = new ChatRoomCollection();
        $sqlMarks = 'SELECT * FROM %s';
        $sql      = static::sqlFormater($sqlMarks, $this->entity->getTableName());

        foreach (DB::query($sql)->fetchAll() as $roomAttributes) {
            $rooms->add(new ChatRoom($roomAttributes));
        }

        return $rooms;
    }

    /**
     * Create a new chat room
     *
     * @param      int     $idUser    The user creator id
     * @param      string  $roomName  The room name
     * @param      int     $maxUsers  The max room users
     * @param      string  $password  The room password DEFAULT ''
     *
     * @return     array   An array with the success and the errors if it failed
     */
    public function createChatRoom(int $idUser, string $roomName, int $maxUsers, string $password = '')
    {
        $success  = false;
        $errors   = array();
        $roomName = trim($roomsName);

        if ($roomName === '') {
            $errors[] = _('The room name cannot be empty');
        }

        if (!is_numeric($maxUsers) || $maxUsers < 2) {
            $errors[] = _('The max number of users must be a number and must be greater than 1');
        }

        $sqlMarks = 'SELECT COUNT(id) FROM %s WHERE name = %s';
        $sql      = static::sqlFormater($sqlMarks, $this->entity->getTableName(), DB::quote($roomName));

        if ((int) DB::query($query)->fetchColumn() > 0) {
            $errors[] = _('This room name already exists');
        }

        if (count($errors) === 0) {
            // Creation
            $query                      = 'SELECT MAX(id) FROM ' . $this->entity->getTableName();
            $this->entity->id           = (int) DB::query($query)->fetchColumn() + 1;
            $this->entity->creator      = $idUser;
            $this->entity->name         = $roomName;
            $this->entity->maxUsers     = $maxUsers;
            $this->entity->password     = $password;
            $this->entity->creationDate = new \DateTime();
            $success                    = $this->saveEntity();
        }

        return array(
            'success'  => $success,
            'errors'   => $errors
        );
    }
}
