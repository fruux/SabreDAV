<?php declare (strict_types=1);

namespace Sabre\CalDAV;

use GuzzleHttp\Psr7\ServerRequest;
use Sabre\VObject;

/**
 * This unittests is created to find out why certain events show up twice.
 *
 * Hopefully, by the time I'm done with this, I've both found the problem, and
 * fixed it :)
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class ExpandEventsDoubleEventsTest extends \Sabre\DAVServerTest {

    protected $setupCalDAV = true;

    protected $caldavCalendars = [
        [
            'id'           => 1,
            'name'         => 'Calendar',
            'principaluri' => 'principals/user1',
            'uri'          => 'calendar1',
        ]
    ];

    protected $caldavCalendarObjects = [
        1 => [
           'event.ics' => [
                'calendardata' => 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:foobar
DTEND;TZID=Europe/Berlin:20120207T191500
RRULE:FREQ=DAILY;INTERVAL=1;COUNT=3
SUMMARY:RecurringEvents 3 times
DTSTART;TZID=Europe/Berlin:20120207T181500
END:VEVENT
BEGIN:VEVENT
CREATED:20120207T111900Z
UID:foobar
DTEND;TZID=Europe/Berlin:20120208T191500
SUMMARY:RecurringEvents 3 times OVERWRITTEN
DTSTART;TZID=Europe/Berlin:20120208T181500
RECURRENCE-ID;TZID=Europe/Berlin:20120208T181500
END:VEVENT
END:VCALENDAR
',
            ],
        ],
    ];

    function testExpand() {

        $request = new ServerRequest(
            'REPORT',
            '/calendars/user1/calendar1',
            [
                'Content-Type' => 'application/xml',
                'Depth' => '1',
            ],
            '<?xml version="1.0" encoding="utf-8" ?>
<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
    <D:prop>
        <C:calendar-data>
            <C:expand start="20120205T230000Z" end="20120212T225959Z"/>
        </C:calendar-data>
        <D:getetag/>
    </D:prop>
    <C:filter>
        <C:comp-filter name="VCALENDAR">
            <C:comp-filter name="VEVENT">
                <C:time-range start="20120205T230000Z" end="20120212T225959Z"/>
            </C:comp-filter>
        </C:comp-filter>
    </C:filter>
</C:calendar-query>'
        );

        $response = $this->request($request);
        $responseBody = $response->getBody()->getContents();


        // Everts super awesome xml parser.
        $body = substr(
            $responseBody,
            $start = strpos($responseBody, 'BEGIN:VCALENDAR'),
            strpos($responseBody, 'END:VCALENDAR') - $start + 13
        );
        $body = str_replace('&#13;', '', $body);

        $vObject = VObject\Reader::read($body);

        // We only expect 3 events
        $this->assertEquals(3, count($vObject->VEVENT), 'We got 6 events instead of 3. Output: ' . $body);

        // TZID should be gone
        $this->assertFalse(isset($vObject->VEVENT->DTSTART['TZID']));

    }

}
