<?php
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace OpenApi\Client;

use OpenAPI\Client\Model\VisitorSegments;
use OpenAPI\Client\Model\VisitorTrackingEvents;

/**
 * Class VisitorsQueue
 *
 * Represents a queue of visitors with methods to manage the queue.
 */
class VisitorsQueue
{
    /**
     * @var int The number of unique visitors in the queue
     */
    public int $length = 0;

    /**
     * @var array<int, array{visitorId: string, segments?: VisitorSegments|null, events: VisitorTrackingEvents[]}> List of visitor data
     */
    private array $items = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->length = 0;
        $this->items = [];
    }

    /**
     * Add or update a visitor in the queue.
     *
     * @param string $visitorId The unique identifier of the visitor
     * @param VisitorTrackingEvents $eventRequest The event to associate with the visitor
     * @param VisitorSegments|null $segments Optional segments for the visitor
     * @return void
     */
    public function push(string $visitorId, VisitorTrackingEvents $eventRequest, ?VisitorSegments $segments = null): void
    {
        $visitorIndex = -1;
        foreach ($this->items as $index => $item) {
            if ($item['visitorId'] === $visitorId) {
                $visitorIndex = $index;
                break;
            }
        }

        if ($visitorIndex !== -1) {
            // Visitor exists, append the event
            $this->items[$visitorIndex]['events'][] = $eventRequest;
        } else {
            // New visitor, create and add to queue
            $visitor = [
                'visitorId' => $visitorId,
                'events' => [$eventRequest]
            ];
            if ($segments !== null) {
                $visitor['segments'] = $segments;
            }
            $this->items[] = $visitor;
            $this->length++;
        }
    }

    /**
     * Reset the queue, clearing all visitors.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->items = [];
        $this->length = 0;
    }

    /**
     * Get the list of visitors in the queue.
     *
     * @return array<int, array{visitorId: string, segments?: VisitorSegments|null, events: VisitorTrackingEvents[]}>
     */
    public function getItems(): array
    {
        return $this->items;
    }
}