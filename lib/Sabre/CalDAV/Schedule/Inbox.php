<?php

namespace Sabre\CalDAV\Schedule;
use Sabre\DAV;
use Sabre\CalDAV;
use Sabre\DAVACL;

/**
 * The CalDAV scheduling inbox 
 *
 * @copyright Copyright (C) 2007-2013 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Inbox extends DAV\Collection implements IInbox {

    /**
     * CalDAV backend
     *
     * @var Backend\BackendInterface
     */
    protected $caldavBackend;

    /**
     * The principal Uri
     *
     * @var string
     */
    protected $principalUri;

    /**
     * Constructor
     *
     * @param string $principalUri
     */
    public function __construct(Backend\BackendInterface $caldavBackend, $principalUri) {

        $this->caldavBackend = $caldavBackend;
        $this->principalUri = $principalUri;

    }

    /**
     * Returns the name of the node.
     *
     * This is used to generate the url.
     *
     * @return string
     */
    public function getName() {

        return 'inbox';

    }

    /**
     * Returns an array with all the child nodes
     *
     * @return \Sabre\DAV\INode[]
     */
    public function getChildren() {

        $objs = $this->caldavBackend->getSchedulingMessages($this->principalUri);
        $children = [];
        foreach($objs as $obj) {
            $obj['acl'] = $this->getACL();
            $children[] = new SchedulingMessage($this->caldavBackend,$this->calendarInfo,$obj);
        }
        return $children;

    }

    /**
     * Returns the owner principal
     *
     * This must be a url to a principal, or null if there's no owner
     *
     * @return string|null
     */
    public function getOwner() {

        return $this->principalUri;

    }

    /**
     * Returns a group principal
     *
     * This must be a url to a principal, or null if there's no owner
     *
     * @return string|null
     */
    public function getGroup() {

        return null;

    }

    /**
     * Returns a list of ACE's for this node.
     *
     * Each ACE has the following properties:
     *   * 'privilege', a string such as {DAV:}read or {DAV:}write. These are
     *     currently the only supported privileges
     *   * 'principal', a url to the principal who owns the node
     *   * 'protected' (optional), indicating that this ACE is not allowed to
     *      be updated.
     *
     * @return array
     */
    public function getACL() {

        return [
            [
                'privilege' => '{DAV:}read',
                'principal' => $this->getOwner(),
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => $this->getOwner() . '/calendar-proxy-read',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => $this->getOwner() . '/calendar-proxy-write',
                'protected' => true,
            ],
        ];

    }

    /**
     * Updates the ACL
     *
     * This method will receive a list of new ACE's.
     *
     * @param array $acl
     * @return void
     */
    public function setACL(array $acl) {

        throw new DAV\Exception\MethodNotAllowed('You\'re not allowed to update the ACL');

    }

    /**
     * Returns the list of supported privileges for this node.
     *
     * The returned data structure is a list of nested privileges.
     * See Sabre\DAVACL\Plugin::getDefaultSupportedPrivilegeSet for a simple
     * standard structure.
     *
     * If null is returned from this method, the default privilege set is used,
     * which is fine for most common usecases.
     *
     * @return array|null
     */
    public function getSupportedPrivilegeSet() {

        return null;

    }

}
