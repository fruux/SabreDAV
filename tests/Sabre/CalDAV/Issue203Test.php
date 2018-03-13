<?php declare (strict_types=1);

namespace Sabre\CalDAV;

use GuzzleHttp\Psr7\ServerRequest;
use Sabre\VObject;

/**
 * This unittest is created to find out why an overwritten DAILY event has wrong DTSTART, DTEND, SUMMARY and RECURRENCEID
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class Issue203Test extends \Sabre\DAVServerTest {

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
UID:20120330T155305CEST-6585fBUVgV
DTSTAMP:20120330T135305Z
DTSTART;TZID=Europe/Berlin:20120326T155200
DTEND;TZID=Europe/Berlin:20120326T165200
RRULE:FREQ=DAILY;COUNT=2;INTERVAL=1
SUMMARY:original summary
TRANSP:OPAQUE
END:VEVENT
BEGIN:VEVENT
UID:20120330T155305CEST-6585fBUVgV
DTSTAMP:20120330T135352Z
DESCRIPTION:
DTSTART;TZID=Europe/Berlin:20120328T155200
DTEND;TZID=Europe/Berlin:20120328T165200
RECURRENCE-ID;TZID=Europe/Berlin:20120327T155200
SEQUENCE:1
SUMMARY:overwritten summary
TRANSP:OPAQUE
END:VEVENT
END:VCALENDAR
',
            ],
        ],
    ];

    function testIssue203() {

        $request = new ServerRequest(
            'REPORT',
            '/calendars/user1/calendar1',
            [
                'Content-Type' => 'application/xml',
                'Depth'        => '1',
            ],
            '<?xml version="1.0" encoding="utf-8" ?>
<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
    <D:prop>
        <C:calendar-data>
            <C:expand start="20120325T220000Z" end="20120401T215959Z"/>
        </C:calendar-data>
        <D:getetag/>
    </D:prop>
    <C:filter>
        <C:comp-filter name="VCALENDAR">
            <C:comp-filter name="VEVENT">
                <C:time-range start="20120325T220000Z" end="20120401T215959Z"/>
            </C:comp-filter>
        </C:comp-filter>
    </C:filter>
</C:calendar-query>');

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

        $this->assertEquals(2, count($vObject->VEVENT));


        $expectedEvents = [
            [
                'DTSTART' => '20120326T135200Z',
                'DTEND'   => '20120326T145200Z',
                'SUMMARY' => 'original summary',
            ],
            [
                'DTSTART'       => '20120328T135200Z',
                'DTEND'         => '20120328T145200Z',
                'SUMMARY'       => 'overwritten summary',
                'RECURRENCE-ID' => '20120327T135200Z',
            ]
        ];

        // try to match agains $expectedEvents array
        foreach ($expectedEvents as $expectedEvent) {

            $matching = false;

            foreach ($vObject->VEVENT as $vevent) {
                /** @var $vevent Sabre\VObject\Component\VEvent */
                foreach ($vevent->children() as $child) {
                    /** @var $child Sabre\VObject\Property */
                    if (isset($expectedEvent[$child->name])) {
                        if ($expectedEvent[$child->name] != $child->getValue()) {
                            continue 2;
                        }
                    }
                }

                $matching = true;
                break;
            }

            $this->assertTrue($matching, 'Did not find the following event in the response: ' . var_export($expectedEvent, true));
        }
    }
}
