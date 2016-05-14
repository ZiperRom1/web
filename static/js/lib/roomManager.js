/**
 * RoomManager object to handle all room Object
 *
 * @module roomManager
 */
define([
    'jquery',
    'module',
    'lodash',
    'room',
    'client',
    'notification',
    'bootstrap-select',
    'bootstrap'
], function ($, module, _, Room, Client, Notification) {
    'use strict';

    /**
     * RoomManager object
     *
     * @param      {WebsocketManager}   WebsocketManager    The websocket manager
     * @param      {Client}             Client              The current Client
     * @param      {Object}             settings            Overridden settings
     *
     * @exports    roomManager
     * @see        module:room
     * @see        module:client
     * @see        module:notification
     * @see        module:websocket
     *
     * @property   {Object}             settings        The roomManager global settings
     * @property   {WebsocketManager}   websocket       The WebsocketManager module
     * @property   {Notification}       notification    The Notification module
     * @property   {Array}              mouseInRoom     Array of rooms ID that tells if the mouse is in a room DOM area
     * @property   {Object}             rooms           Collection of room module
     *
     * @constructor
     * @alias      module:roomManager
     */
    var RoomManager = function (WebsocketManager, Client, settings) {
        this.settings         = $.extend(true, {}, this.settings, module.config(), settings);
        this.websocketManager = WebsocketManager;
        this.client           = Client;
        this.notification     = new Notification();
        this.mouseInRoom      = [];
        this.rooms            = {};

        this.initEvents();
    };

    RoomManager.prototype = {
        /*==============================
        =            Events            =
        ==============================*/

        /**
         * Initialize all the events
         *
         * @method     initEvents
         */
        initEvents: function () {
            // Connect to a room
            $('body').on(
                'click',
                this.settings.selectors.roomConnect.div + ' ' + this.settings.selectors.roomConnect.connect,
                $.proxy(this.connectEvent, this)
            );
        },

        /**
         * Event fired when a user want to connect to a room
         *
         * @method     connectEvent
         */
        connectEvent: function () {
            var connectDiv = $(this.settings.selectors.roomConnect.div),
                pseudonym  = connectDiv.find(this.settings.selectors.roomConnect.pseudonym).val(),
                roomId     = connectDiv.find('select' + this.settings.selectors.roomConnect.name).val(),
                password   = connectDiv.find(this.settings.selectors.roomConnect.password).val();
            //@todo error message when no room selected...
            this.connect(pseudonym, roomId, password);
        },

        /*=====  End of Events  ======*/

        /*==================================================================
        =            Actions that query to the WebSocket server            =
        ==================================================================*/

        /**
         * Get the all rooms
         *
         * @method     getAll
         */
        getAll: function () {
            this.websocketManager.send(JSON.stringify({
                "service": [this.settings.serviceName],
                "action" : "getAll"
            }));
        },

        /**
         * Connect to a room
         *
         * @method     connect
         * @param      {String}  pseudonym  The user pseudonym
         * @param      {Number}  roomId     The room id
         * @param      {String}  password   The room password
         */
        connect: function (pseudonym, roomId, password) {
            this.websocketManager.send(JSON.stringify({
                "service"  : [this.settings.serviceName],
                "action"   : "connect",
                "pseudonym": pseudonym || this.client.getUser().getPseudonym(),
                "roomId"   : roomId,
                "password" : password || ''
            }));
        },

        /*=====  End of Actions that query to the WebSocket server  ======*/

        /*==================================================================
        =            Callbacks after WebSocket server responses            =
        ==================================================================*/

        /**
         * Handle the WebSocker server response and process action with the right callback
         *
         * @method     wsCallbackDispatcher
         * @param      {Object}  data    The server JSON reponse
         */
        wsCallbackDispatcher: function (data) {
            if (typeof this[data.action + 'Callback'] === 'function') {
                this[data.action + 'Callback'](data);
            }
        },

        /**
         * Add all rooms to the rooms collection
         *
         * @method     getAllCallback
         * @param      {Object}  data    The server JSON response
         */
        getAllCallback: function (data) {
            _.map(data.rooms, _.bind(this.addRoom, this));
        },

        /**
         * Insert the room in DOM if it is not already in and add clients to the room
         *
         * @method     connectCallback
         * @param      {Object}  data    The server JSON response
         *
         * @todo Add roomBan collection from server ?
         */
        connectCallback: function (data) {
            var self = this;

            if (data.success) {
                _.forEach(data.clients, function (clientAttributes) {
                    self.addClient(clientAttributes, self.rooms[data.roomId], data.pseudonyms);
                });

                if (!this.isRoomInDom(this.rooms[data.roomId])) {
                    this.insertRoomInDOM(this.rooms[data.roomId]);
                }
            }

            this.notification.add(data.text);
        },

        /**
         * Add a client in the room
         *
         * @method     updateClientsCallback
         * @param      {Object}  data    The server JSON response
         */
        addClientInRoomCallback: function (data) {
            var client = new Client(data.client);

            client.setPseudonym(data.pseudonym);
            this.rooms[data.roomId].addClient(client);
        },

        /**
         * Callback after updated a user room right
         *
         * @method     updateUserRightCallback
         * @param      {Object}  data    The server JSON response
         */
        updateUserRightCallback: function (data) {
            this.notification.add(data.text);
        },

        /**
         * Change a user right in the admin panel
         *
         * @method     changeUserRightCallback
         * @param      {Object}  data    The server JSON response
         */
        changeUserRightCallback: function (data) {
            console.log("changeUserRightCallback", data);
        },

        /*=====  End of Callbacks after WebSocket server responses  ======*/

        /*=========================================
        =            Utilities methods            =
        =========================================*/

        /**
         * Add a client in a room
         *
         * @method     addClient
         * @param      {Object}  clientAttributes  The client attributes
         * @param      {Room}    room              The room to add the client in
         * @param      {Object}  pseudonyms        The room pseudonyms list
         */
        addClient: function (clientAttributes, room, pseudonyms) {
            var client = new Client(clientAttributes);

            client.setPseudonym(pseudonyms[client.getId()]);
            room.addClient(client);
        },

        /**
         * Add a room to the rooms collection
         *
         * @method     addRoom
         * @param      {Object}  roomAttributes  The room attributes as JSON
         */
        addRoom: function (roomAttributes) {
            var room = new Room(roomAttributes.room);

            room.setNumberOfConnectedClients(roomAttributes.connectedClients);

            if (_.isUndefined(this.rooms[room.getId()])) {
                this.rooms[room.getId()] = room;
            }

            this.updateRoomList();
        },

        /**
         * Update the room list in the select picker
         *
         * @method     updateRoomList
         */
        updateRoomList: function () {
            var publicRooms  = [],
                privateRooms = [],
                select       = $(
                    this.settings.selectors.roomConnect.div + ' ' + this.settings.selectors.roomConnect.name
                ),
                option;

            _.forEach(this.rooms, function (room) {
                option = $('<option>', {
                    "value"       : room.getId(),
                    "data-subtext": '(' + room.getNumberOfConnectedClients() + '/' + room.getMaxUsers() + ')',
                    "data-type"   : room.isPublic() ? 'public' : 'private',
                    "text"        : room.getName()
                });

                if (room.isPublic()) {
                    publicRooms.push(option);
                } else {
                    privateRooms.push(option);
                }
            });

            select.find(this.settings.selectors.roomConnect.publicRooms).html(publicRooms);
            select.find(this.settings.selectors.roomConnect.privateRooms).html(privateRooms);
            select.selectpicker('refresh');
        },

        /**
         * Insert a room in the DOM with a Room object
         *
         * @method     insertRoomInDOM
         * @param      {Room}  room    The Room object to insert
         */
        insertRoomInDOM: function (room) {
            var roomSample = $(this.settings.selectors.global.roomSample),
                newRoom    = roomSample.clone(true),
                modalSample, newModal, id;

            newRoom.attr('data-id', room.getId());
            newRoom.removeAttr('id');
            newRoom.removeClass('hide');
            newRoom.find(this.settings.selectors.global.roomName).text(room.getName());
            newRoom.find(this.settings.selectors.roomAction.showUsers).popover({
                content: function () {
                    var list = $('<ul>');

                    _.forEach(room.getPseudonyms(), function (pseudonym) {
                        list.append($('<li>', {"text": pseudonym}));
                    });

                    return list.html();
                }
            });
            // @todo this.loadHistoric(room);
            this.mouseInRoom[room.getId()] = false;
            room.setOpened(true);

            $(this.settings.selectors.global.rooms).append(newRoom);
            // Modal room chat administration creation if the user is connected
            if (this.client.getUser().isConnected()) {
                modalSample = $(this.settings.selectors.administrationPanel.modalSample);
                newModal    = modalSample.clone();
                id          = 'chat-admin-' + room.getId();

                newModal.attr({
                    "id"          : id,
                    "data-room-id": room.getId()
                });

                newModal.find(this.settings.selectors.administrationPanel.roomName).text(room.getName());
                newModal.find(this.settings.selectors.administrationPanel.inputRoomName).val(room.getName());
                newModal.find(this.settings.selectors.administrationPanel.inputRoomPassword).val(room.getPassword());
                newRoom.find(this.settings.selectors.roomAction.administration).attr('data-target', '#' + id);
                // @todo this.updateRoomUsersRights(newModal, data.usersRights, room.getId());

                modalSample.after(newModal);
            }
        },

        /**
         * Determine if room is in DOM
         *
         * @method     isRoomInDom
         * @param      {Room}     room    The room object to check
         * @return     {Boolean}  True if room is in DOM, False otherwise
         */
        isRoomInDom: function (room) {
            return $(this.settings.selectors.global.room + '[data-id="' + room.getId() + '"]').length > 0;
        }

        /*=====  End of Utilities methods  ======*/

    };

    return RoomManager;
});
