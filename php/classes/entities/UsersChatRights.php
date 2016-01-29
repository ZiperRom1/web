<?php
/**
 * UsersChatRights entity
 *
 * @category Entity
 * @author   Romain Laneuville <romain.laneuville@hotmail.fr>
 */

namespace classes\entities;

use \abstracts\Entity as Entity;

/**
 * UsersChatRights entity that extends the Entity abstact class
 *
 * @property integer $idUser    The user id
 * @property string  $roomName  The room name
 * @property boolean $kick      The kick right
 * @property boolean $ban       The ban right
 * @property boolean $grant     The grant right
 * @property boolean $rename    The rename right
 * @property boolean $password  The change password right
 *
 * @class UsersChatRights
 */
class UsersChatRights extends Entity
{
    /*=====================================
    =            Magic methods            =
    =====================================*/

    /**
     * Constructor that calls the parent Entity constructor
     */
    public function __construct()
    {
        parent::__construct('UsersChatRights');
    }

    /**
     * To array overriden to handle boolean cast type
     *
     * @return array Array with columns name on keys and columns value on values
     * @todo   See if boolean cast conversation can be done automatically
     */
    public function __toArray()
    {
        return array(
            'roomName' => $this->roomName,
            'kick'     => (bool) $this->kick,
            'ban'      => (bool) $this->ban,
            'grant'    => (bool) $this->grant,
            'rename'   => (bool) $this->rename,
            'password' => (bool) $this->password
        );
    }

    /*-----  End of Magic methods  ------*/
}
