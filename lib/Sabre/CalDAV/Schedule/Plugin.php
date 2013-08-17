<?php

namespace Sabre\CalDAV\Schedule;

use
    Sabre\DAV\Server,
    Sabre\DAV\ServerPlugin,
    Sabre\DAV\Property\Href,
    Sabre\DAV\Property\HrefList,
    Sabre\HTTP\RequestInterface,
    Sabre\HTTP\ResponseInterface,
    Sabre\VObject,
    Sabre\DAVACL,
    Sabre\CalDAV\ICalendar,
    Sabre\DAV\Exception\NotFound,
    Sabre\DAV\Exception\Forbidden,
    Sabre\DAV\Exception\BadRequest,
    Sabre\DAV\Exception\NotImplemented;

/**
 * CalDAV scheduling plugin.
 *
 * This plugin provides the functionality added by the "Scheduling Extensions
 * to CalDAV" standard, as defined in RFC6638.
 *
 * @copyright Copyright (C) 2007-2013 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Plugin extends ServerPlugin {

    /**
     * This is the official CalDAV namespace
     */
    const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    /**
     * Reference to main Server object.
     *
     * @var Server
     */
    protected $server;

    /**
     * The email handler for invites and other scheduling messages.
     *
     * @var IMip
     */
    protected $imipHandler;

    /**
     * Sets the iMIP handler.
     *
     * iMIP = The email transport of iCalendar scheduling messages. Setting
     * this is optional, but if you want the server to allow invites to be sent
     * out, you must set a handler.
     *
     * Specifically iCal will plain assume that the server supports this. If
     * the server doesn't, iCal will display errors when inviting people to
     * events.
     *
     * @param IMip $imipHandler
     * @return void
     */
    public function setIMipHandler(IMip $imipHandler) {

        $this->imipHandler = $imipHandler;

    }

    /**
     * Returns a list of features for the DAV: HTTP header.
     *
     * @return array
     */
    public function getFeatures() {

        return ['calendar-auto-schedule'];

    }

    /**
     * Returns the name of the plugin.
     *
     * Using this name other plugins will be able to access other plugins
     * using Server::getPlugin
     *
     * @return string
     */
    public function getPluginName() {

        return 'caldav-schedule';

    }

    /**
     * Initializes the plugin
     *
     * @param Server $server
     * @return void
     */
    public function initialize(Server $server) {

        $this->server = $server;
        $server->on('method:POST', [$this,'httpPost']);
        $server->on('beforeGetProperties', [$this,'beforeGetProperties']);

        /**
         * This information ensures that the {DAV:}resourcetype property has
         * the correct values.
         */
        $server->resourceTypeMapping['\\Sabre\\CalDAV\\Schedule\\IOutbox'] = '{urn:ietf:params:xml:ns:caldav}schedule-outbox';
        $server->resourceTypeMapping['\\Sabre\\CalDAV\\Schedule\\IInbox'] = '{urn:ietf:params:xml:ns:caldav}schedule-inbox';

        /**
         * Properties we protect are made read-only by the server.
         */
        array_push($server->protectedProperties,
            '{' . self::NS_CALDAV . '}schedule-inbox-URL',
            '{' . self::NS_CALDAV . '}schedule-outbox-URL',
            '{' . self::NS_CALDAV . '}calendar-user-address-set',
            '{' . self::NS_CALDAV . '}calendar-user-type'
        );

    }

    /**
     * Use this method to tell the server this plugin defines additional
     * HTTP methods.
     *
     * This method is passed a uri. It should only return HTTP methods that are
     * available for the specified uri.
     *
     * @param string $uri
     * @return array
     */
    public function getHTTPMethods($uri) {

        try {
            $node = $this->server->tree->getNodeForPath($uri);
        } catch (NotFound $e) {
            return [];
        }

        if ($node instanceof IOutbox) {
            return ['POST'];
        }

        return [];

    }

    /**
     * This method handles POST request for the outbox.
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool
     */
    public function httpPost(RequestInterface $request, ResponseInterface $response) {

        // Checking if this is a text/calendar content type
        $contentType = $request->getHeader('Content-Type');
        if (strpos($contentType, 'text/calendar')!==0) {
            return;
        }

        $path = $request->getPath();

        // Checking if we're talking to an outbox
        try {
            $node = $this->server->tree->getNodeForPath($path);
        } catch (NotFound $e) {
            return;
        }
        if (!$node instanceof IOutbox)
            return;

        $this->server->transactionType = 'post-caldav-outbox';
        $this->outboxRequest($node, $request, $response);

        // Returning false breaks the event chain and tells the server we've
        // handled the request.
        return false;

    }


    /**
     * beforeGetProperties
     *
     * This method handler is invoked before any after properties for a
     * resource are fetched. This allows us to add in any CalDAV specific
     * properties.
     *
     * @param string $path
     * @param \Sabre\DAV\INode $node
     * @param array $requestedProperties
     * @param array $returnedProperties
     * @return void
     */
    public function beforeGetProperties($path, \Sabre\DAV\INode $node, &$requestedProperties, &$returnedProperties) {

        $caldavPlugin = $this->server->getPlugin('caldav');

        if ($node instanceof DAVACL\IPrincipal) {

            $principalUrl = $node->getPrincipalUrl();

            // schedule-outbox-URL property
            $scheduleProp = '{' . self::NS_CALDAV . '}schedule-outbox-URL';
            if (in_array($scheduleProp,$requestedProperties)) {

                $calendarHomePath = $caldavPlugin->getCalendarHomeForPrincipal($principalUrl);
                $outboxPath = $calendarHomePath . '/outbox';

                unset($requestedProperties[array_search($scheduleProp, $requestedProperties)]);
                $returnedProperties[200][$scheduleProp] = new Href($outboxPath);

            }

            // schedule-inbox-URL property
            $scheduleProp = '{' . self::NS_CALDAV . '}schedule-inbox-URL';
            if (in_array($scheduleProp,$requestedProperties)) {

                $calendarHomePath = $caldavPlugin->getCalendarHomeForPrincipal($principalUrl);
                $inboxPath = $calendarHomePath . '/inbox';

                unset($requestedProperties[array_search($scheduleProp, $requestedProperties)]);
                $returnedProperties[200][$scheduleProp] = new Href($inboxPath);

            }


            // calendar-user-address-set property
            $calProp = '{' . self::NS_CALDAV . '}calendar-user-address-set';
            if (in_array($calProp,$requestedProperties)) {

                $addresses = $node->getAlternateUriSet();
                $addresses[] = $this->server->getBaseUri() . $node->getPrincipalUrl() . '/';
                unset($requestedProperties[array_search($calProp, $requestedProperties)]);
                $returnedProperties[200][$calProp] = new HrefList($addresses, false);

            }

        } // instanceof IPrincipal

    }


    /**
     * This method handles POST requests to the schedule-outbox.
     *
     * Currently, two types of requests are support:
     *   * FREEBUSY requests from RFC 6638
     *   * Simple iTIP messages from draft-desruisseaux-caldav-sched-04
     *
     * The latter is from an expired early draft of the CalDAV scheduling
     * extensions, but iCal depends on a feature from that spec, so we
     * implement it.
     *
     * @param IOutbox $outboxNode
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return void
     */
    public function outboxRequest(IOutbox $outboxNode, RequestInterface $request, ResponseInterface $response) {

        $outboxPath = $request->getPath();

        // Parsing the request body
        try {
            $vObject = VObject\Reader::read($request->getBody());
        } catch (VObject\ParseException $e) {
            throw new BadRequest('The request body must be a valid iCalendar object. Parse error: ' . $e->getMessage());
        }

        // The incoming iCalendar object must have a METHOD property, and a
        // component. The combination of both determines what type of request
        // this is.
        $componentType = null;
        foreach($vObject->getComponents() as $component) {
            if ($component->name !== 'VTIMEZONE') {
                $componentType = $component->name;
                break;
            }
        }
        if (is_null($componentType)) {
            throw new BadRequest('We expected at least one VTODO, VJOURNAL, VFREEBUSY or VEVENT component');
        }

        // Validating the METHOD
        $method = strtoupper((string)$vObject->METHOD);
        if (!$method) {
            throw new BadRequest('A METHOD property must be specified in iTIP messages');
        }

        // So we support two types of requests:
        //
        // REQUEST with a VFREEBUSY component
        // REQUEST, REPLY, ADD, CANCEL on VEVENT components

        $acl = $this->server->getPlugin('acl');

        if ($componentType === 'VFREEBUSY' && $method === 'REQUEST') {

            $acl && $acl->checkPrivileges($outboxPath, '{' . self::NS_CALDAV . '}schedule-query-freebusy');
            $this->handleFreeBusyRequest($outboxNode, $vObject, $request, $response);

        } elseif ($componentType === 'VEVENT' && in_array($method, ['REQUEST','REPLY','ADD','CANCEL'])) {

            $acl && $acl->checkPrivileges($outboxPath, '{' . Plugin::NS_CALDAV . '}schedule-post-vevent');
            $this->handleEventNotification($outboxNode, $vObject, $request, $response);

        } else {

            throw new NotImplemented('SabreDAV supports only VFREEBUSY (REQUEST) and VEVENT (REQUEST, REPLY, ADD, CANCEL)');

        }

    }

    /**
     * This method handles the REQUEST, REPLY, ADD and CANCEL methods for
     * VEVENT iTip messages.
     *
     * @param IOutbox $outboxNode
     * @param VObject\Component $vObject
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return void
     */
    protected function handleEventNotification(IOutbox $outboxNode, VObject\Component $vObject, RequestInterface $request, ResponseInterface $response) {

        $originator = $request->getHeader('Originator');
        $recipients = $request->getHeader('Recipient');

        if (!$originator) {
            throw new BadRequest('The Originator: header must be specified when making POST requests');
        }
        if (!$recipients) {
            throw new BadRequest('The Recipient: header must be specified when making POST requests');
        }

        $recipients = explode(',',$recipients);
        foreach($recipients as $k=>$recipient) {

            $recipient = trim($recipient);
            if (!preg_match('/^mailto:(.*)@(.*)$/i', $recipient)) {
                throw new BadRequest('Recipients must start with mailto: and must be valid email address');
            }
            $recipient = substr($recipient, 7);
            $recipients[$k] = $recipient;
        }

        // We need to make sure that 'originator' matches the currently
        // authenticated user.
        $aclPlugin = $this->server->getPlugin('acl');
        if (is_null($aclPlugin)) throw new DAV\Exception('The ACL plugin must be loaded for scheduling to work');
        $principal = $aclPlugin->getCurrentUserPrincipal();

        $props = $this->server->getProperties($principal, [
            '{' . self::NS_CALDAV . '}calendar-user-address-set',
        ]);

        $addresses = [];
        if (isset($props['{' . self::NS_CALDAV . '}calendar-user-address-set'])) {
            $addresses = $props['{' . self::NS_CALDAV . '}calendar-user-address-set']->getHrefs();
        }

        $found = false;
        foreach($addresses as $address) {

            // Trimming the / on both sides, just in case..
            if (rtrim(strtolower($originator),'/') === rtrim(strtolower($address),'/')) {
                $found = true;
                break;
            }

        }

        if (!$found) {
            throw new Forbidden('The addresses specified in the Originator header did not match any addresses in the owners calendar-user-address-set header');
        }

        // If the Originator header was a url, and not a mailto: address..
        // we're going to try to pull the mailto: from the vobject body.
        if (strtolower(substr($originator,0,7)) !== 'mailto:') {
            $originator = (string)$vObject->VEVENT->ORGANIZER;

        }
        if (strtolower(substr($originator,0,7)) !== 'mailto:') {
            throw new Forbidden('Could not find mailto: address in both the Orignator header, and the ORGANIZER property in the VEVENT');
        }
        $originator = substr($originator,7);

        $result = $this->iMIPMessage($originator, $recipients, $vObject, $principal);
        $response->setStatus(200);
        $response->setHeader('Content-Type','application/xml');
        $response->setBody($this->generateScheduleResponse($result));

    }

    /**
     * Sends an iMIP message by email.
     *
     * This method must return an array with status codes per recipient.
     * This should look something like:
     *
     * [
     *    'user1@example.org' => '2.0;Success'
     * ]
     *
     * Formatting for this status code can be found at:
     * https://tools.ietf.org/html/rfc5545#section-3.8.8.3
     *
     * A list of valid status codes can be found at:
     * https://tools.ietf.org/html/rfc5546#section-3.6
     *
     * @param string $originator
     * @param array $recipients
     * @param VObject\Component $vObject
     * @param string $principal Principal url
     * @return array
     */
    protected function iMIPMessage($originator, array $recipients, VObject\Component $vObject, $principal) {

        if (!$this->imipHandler) {
            $resultStatus = '5.2;This server does not support this operation';
        } else {
            $this->imipHandler->sendMessage($originator, $recipients, $vObject, $principal);
            $resultStatus = '2.0;Success';
        }

        $result = [];
        foreach($recipients as $recipient) {
            $result[$recipient] = $resultStatus;
        }

        return $result;

    }

    /**
     * Generates a schedule-response XML body
     *
     * The recipients array is a key->value list, containing email addresses
     * and iTip status codes. See the iMIPMessage method for a description of
     * the value.
     *
     * @param array $recipients
     * @return string
     */
    public function generateScheduleResponse(array $recipients) {

        $dom = new \DOMDocument('1.0','utf-8');
        $dom->formatOutput = true;
        $xscheduleResponse = $dom->createElement('cal:schedule-response');
        $dom->appendChild($xscheduleResponse);

        foreach($this->server->xmlNamespaces as $namespace=>$prefix) {

            $xscheduleResponse->setAttribute('xmlns:' . $prefix, $namespace);

        }

        foreach($recipients as $recipient=>$status) {
            $xresponse = $dom->createElement('cal:response');

            $xrecipient = $dom->createElement('cal:recipient');
            $xrecipient->appendChild($dom->createTextNode($recipient));
            $xresponse->appendChild($xrecipient);

            $xrequestStatus = $dom->createElement('cal:request-status');
            $xrequestStatus->appendChild($dom->createTextNode($status));
            $xresponse->appendChild($xrequestStatus);

            $xscheduleResponse->appendChild($xresponse);

        }

        return $dom->saveXML();

    }

    /**
     * This method is responsible for parsing a free-busy query request and
     * returning it's result.
     *
     * @param IOutbox $outbox
     * @param VObject\Component $vObject
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return string
     */
    protected function handleFreeBusyRequest(IOutbox $outbox, VObject\Component $vObject, RequestInterface $request, ResponseInterface $response) {

        $vFreeBusy = $vObject->VFREEBUSY;
        $organizer = $vFreeBusy->organizer;

        $organizer = (string)$organizer;

        // Validating if the organizer matches the owner of the inbox.
        $owner = $outbox->getOwner();

        $caldavNS = '{' . self::NS_CALDAV . '}';

        $uas = $caldavNS . 'calendar-user-address-set';
        $props = $this->server->getProperties($owner, [$uas]);

        if (empty($props[$uas]) || !in_array($organizer, $props[$uas]->getHrefs())) {
            throw new Forbidden('The organizer in the request did not match any of the addresses for the owner of this inbox');
        }

        if (!isset($vFreeBusy->ATTENDEE)) {
            throw new BadRequest('You must at least specify 1 attendee');
        }

        $attendees = [];
        foreach($vFreeBusy->ATTENDEE as $attendee) {
            $attendees[]= (string)$attendee;
        }


        if (!isset($vFreeBusy->DTSTART) || !isset($vFreeBusy->DTEND)) {
            throw new BadRequest('DTSTART and DTEND must both be specified');
        }

        $startRange = $vFreeBusy->DTSTART->getDateTime();
        $endRange = $vFreeBusy->DTEND->getDateTime();

        $results = [];
        foreach($attendees as $attendee) {
            $results[] = $this->getFreeBusyForEmail($attendee, $startRange, $endRange, $vObject);
        }

        $dom = new \DOMDocument('1.0','utf-8');
        $dom->formatOutput = true;
        $scheduleResponse = $dom->createElement('cal:schedule-response');
        foreach($this->server->xmlNamespaces as $namespace=>$prefix) {

            $scheduleResponse->setAttribute('xmlns:' . $prefix,$namespace);

        }
        $dom->appendChild($scheduleResponse);

        foreach($results as $result) {
            $xresponse = $dom->createElement('cal:response');

            $recipient = $dom->createElement('cal:recipient');
            $recipientHref = $dom->createElement('d:href');

            $recipientHref->appendChild($dom->createTextNode($result['href']));
            $recipient->appendChild($recipientHref);
            $xresponse->appendChild($recipient);

            $reqStatus = $dom->createElement('cal:request-status');
            $reqStatus->appendChild($dom->createTextNode($result['request-status']));
            $xresponse->appendChild($reqStatus);

            if (isset($result['calendar-data'])) {

                $calendardata = $dom->createElement('cal:calendar-data');
                $calendardata->appendChild($dom->createTextNode(str_replace("\r\n","\n",$result['calendar-data']->serialize())));
                $xresponse->appendChild($calendardata);

            }
            $scheduleResponse->appendChild($xresponse);
        }

        $response->setStatus(200);
        $response->setHeader('Content-Type','application/xml');
        $response->setBody($dom->saveXML());

    }

    /**
     * Returns free-busy information for a specific address. The returned
     * data is an array containing the following properties:
     *
     * calendar-data : A VFREEBUSY VObject
     * request-status : an iTip status code.
     * href: The principal's email address, as requested
     *
     * The following request status codes may be returned:
     *   * 2.0;description
     *   * 3.7;description
     *
     * @param string $email address
     * @param \DateTime $start
     * @param \DateTime $end
     * @param VObject\Component $request
     * @return array
     */
    protected function getFreeBusyForEmail($email, \DateTime $start, \DateTime $end, VObject\Component $request) {

        $caldavNS = '{' . Plugin::NS_CALDAV . '}';

        $aclPlugin = $this->server->getPlugin('acl');
        if (substr($email,0,7)==='mailto:') $email = substr($email,7);

        $result = $aclPlugin->principalSearch(
            ['{http://sabredav.org/ns}email-address' => $email],
            [
                '{DAV:}principal-URL', $caldavNS . 'calendar-home-set',
                '{http://sabredav.org/ns}email-address',
            ]
        );

        if (!count($result)) {
            return [
                'request-status' => '3.7;Could not find principal',
                'href' => 'mailto:' . $email,
            ];
        }

        if (!isset($result[0][200][$caldavNS . 'calendar-home-set'])) {
            return [
                'request-status' => '3.7;No calendar-home-set property found',
                'href' => 'mailto:' . $email,
            ];
        }
        $homeSet = $result[0][200][$caldavNS . 'calendar-home-set']->getHref();

        // Grabbing the calendar list
        $objects = [];
        foreach($this->server->tree->getNodeForPath($homeSet)->getChildren() as $node) {
            if (!$node instanceof ICalendar) {
                continue;
            }
            $aclPlugin->checkPrivileges($homeSet . $node->getName() ,$caldavNS . 'read-free-busy');

            // Getting the list of object uris within the time-range
            $urls = $node->calendarQuery([
                'name' => 'VCALENDAR',
                'comp-filters' => [
                    [
                        'name' => 'VEVENT',
                        'comp-filters' => [],
                        'prop-filters' => [],
                        'is-not-defined' => false,
                        'time-range' => [
                            'start' => $start,
                            'end' => $end,
                        ],
                    ],
                ],
                'prop-filters' => [],
                'is-not-defined' => false,
                'time-range' => null,
            ]);

            $calObjects = array_map(function($url) use ($node) {
                $obj = $node->getChild($url)->get();
                return $obj;
            }, $urls);

            $objects = array_merge($objects,$calObjects);

        }

        $vcalendar = new VObject\Component\VCalendar();
        $vcalendar->METHOD = 'REPLY';

        $generator = new VObject\FreeBusyGenerator();
        $generator->setObjects($objects);
        $generator->setTimeRange($start, $end);
        $generator->setBaseObject($vcalendar);

        $result = $generator->getResult();

        $vcalendar->VFREEBUSY->ATTENDEE = 'mailto:' . $email;
        $vcalendar->VFREEBUSY->UID = (string)$request->VFREEBUSY->UID;
        $vcalendar->VFREEBUSY->ORGANIZER = clone $request->VFREEBUSY->ORGANIZER;

        return [
            'calendar-data' => $result,
            'request-status' => '2.0;Success',
            'href' => 'mailto:' . $email,
        ];
    }
}
