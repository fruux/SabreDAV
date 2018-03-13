<?php declare (strict_types=1);

namespace Sabre\CalDAV;

use GuzzleHttp\Psr7\ServerRequest;
use Sabre\VObject;

/**
 * This unit test was created to find out why recurring events have wrong DTSTART value
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class ExpandEventsDTSTARTandDTENDbyDayTest extends \Sabre\DAVServerTest {

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
RRULE:FREQ=WEEKLY;INTERVAL=1;BYDAY=TU,TH
SUMMARY:RecurringEvents on tuesday and thursday
DTSTART;TZID=Europe/Berlin:20120207T181500
END:VEVENT
END:VCALENDAR
',
            ],
        ],
    ];

    function testExpandRecurringByDayEvent() {

        $request = new ServerRequest('REPORT', '/calendars/user1/calendar1', [
            'Content-Type' => 'application/xml',
            'Depth' => '1',
        ], '<?xml version="1.0" encoding="utf-8" ?>
<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
    <D:prop>
        <C:calendar-data>
            <C:expand start="20120210T230000Z" end="20120217T225959Z"/>
        </C:calendar-data>
        <D:getetag/>
    </D:prop>
    <C:filter>
        <C:comp-filter name="VCALENDAR">
            <C:comp-filter name="VEVENT">
                <C:time-range start="20120210T230000Z" end="20120217T225959Z"/>
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

        // check if DTSTARTs and DTENDs are correct
        foreach ($vObject->VEVENT as $vevent) {
            /** @var $vevent VObject\Component\VEvent */
            foreach ($vevent->children() as $child) {
                /** @var $child VObject\Property */
                if ($child->name == 'DTSTART') {
                    // DTSTART has to be one of two valid values
                    $this->assertContains($child->getValue(), ['20120214T171500Z', '20120216T171500Z'], 'DTSTART is not a valid value: ' . $child->getValue());
                } elseif ($child->name == 'DTEND') {
                    // DTEND has to be one of two valid values
                    $this->assertContains($child->getValue(), ['20120214T181500Z', '20120216T181500Z'], 'DTEND is not a valid value: ' . $child->getValue());
                }
            }
        }
    }

}
