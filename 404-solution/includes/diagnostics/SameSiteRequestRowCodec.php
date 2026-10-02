<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The stored value of one same-site request row: a versioned, delimited
 * string, and the only code that writes or reads that layout.
 *
 * A persisted format is a forward-compatibility contract (a 4.3.5 worker, or a
 * rolled-back site, reads what this build writes), which is why the layout,
 * its marker and its bounds live apart from the statements that store the row
 * (ABJ_404_Solution_SameSiteRequestRegistry): the storage can change without
 * touching the format, and the format cannot be changed by accident while
 * editing a query.
 *
 * @phpstan-type Row array{started_at_ms: int, channel: string, action: string, pid: int|null, phase?: string, timeline?: string}
 * @phpstan-type Entry array{option_name: string, started_at_ms: int, channel: string, action: string, pid: int|null, phase: string, timeline: string}
 */
final class ABJ_404_Solution_SameSiteRequestRowCodec {

    /**
     * Cap on the encoded timeline carried as the row's last field. A full
     * timeline is about 400 bytes; the cap bounds a pathological one rather
     * than rejecting the row.
     */
    const MAX_TIMELINE_CHARS = 2048;

    /**
     * Leading field of a row that carries a timeline. It is deliberately not
     * a number: the 4.3.5 reader (already on sites, and what a rollback
     * returns to) accepts a row only when its first field is all digits and
     * otherwise skips it without counting or deleting it. A timeline-bearing
     * row written without this marker is read by that release as a legacy
     * row whose `phase` is the timeline's JSON, which is garbage in a
     * support report. Only ever add a new marker for a new layout; never
     * reuse or repurpose 'v2'.
     */
    const ROW_MARKER = 'v2';


    /**
     * The stored value: start time, channel, WordPress action, PID, phase,
     * and the request's encoded phase timeline.
     *
     * Two layouts, both read by decodeValue():
     *   - with a timeline: `v2|<ms>|<channel>|<action>|<pid>|<phase>|<timeline>`
     *   - without one: `<ms>|<channel>|<action>|<pid>|<phase>` (the 4.3.5 layout)
     * The marker on the first keeps a 4.3.5 worker from misreading the
     * timeline as a phase (see ROW_MARKER); the second stays byte-identical to
     * 4.3.5, so an older worker keeps counting these rows correctly. Readers
     * also accept rows with four fields (before phases) and the unmarked
     * six-field layout of unreleased builds.
     *
     * A flat delimited string rather than JSON because every field is a
     * bounded scalar and the row is written on a path whose cost is being
     * measured; decodeValue() is the only reader. The delimiter is stripped
     * from phase and timeline for the same reason the decoder bounds every
     * field: a value that could introduce a further part would shift the
     * meaning of the parts after it.
     *
     * Keyed, not positional: channel, action, phase and timeline are all
     * strings, so a swapped pair would type-check and store the timeline in
     * the phase field. The keys are the ones decode() returns, so a decoded
     * entry can be re-encoded as it is (option_name is ignored).
     *
     * @param Row $row
     * @return string
     */
    public static function encode(array $row): string {
        $timeline = str_replace('|', '', substr($row['timeline'] ?? '', 0, self::MAX_TIMELINE_CHARS));
        $pid = $row['pid'];
        $fields = $row['started_at_ms'] . '|' . $row['channel'] . '|' . $row['action'] . '|'
            . ($pid !== null ? (string)$pid : '')
            . '|' . str_replace('|', '', $row['phase'] ?? '');
        if ($timeline === '') {
            return $fields;
        }
        return self::ROW_MARKER . '|' . $fields . '|' . $timeline;
    }

    /**
     * The encoded timeline inside a stored row value, or '' when the row
     * carries none. The one place besides decode() that knows the layout, so
     * a caller holding a raw value (findTimeline's result) never re-splits it.
     *
     * @param string $raw
     * @return string
     */
    public static function timelineOfValue(string $raw): string {
        $parts = self::splitValue($raw);
        return $parts === null ? '' : $parts[5];
    }

    /**
     * A stored value split into exactly six fields (ms, channel, action, pid,
     * phase, timeline; absent ones as ''), or null when it is not a row this
     * class understands. Accepts every generation of the layout.
     *
     * @return array<int, string>|null
     */
    private static function splitValue(string $raw): ?array {
        if (strpos($raw, self::ROW_MARKER . '|') === 0) {
            $parts = explode('|', substr($raw, strlen(self::ROW_MARKER) + 1), 6);
        } else {
            $parts = explode('|', $raw, 6);
        }
        if (!ctype_digit($parts[0])) {
            return null;
        }
        return array_pad($parts, 6, '');
    }

    /**
     * One database row as a structured entry, or null when the row is not one
     * the registry wrote in a format this codec understands.
     *
     * @param mixed $row
     * @return Entry|null
     */
    public static function decodeRow($row): ?array {
        if (!is_array($row)) {
            return null;
        }
        // Case-insensitive: MySQL drivers vary the case of returned column
        // names, and a registry that silently reads nothing is the failure
        // mode the whole census exists to avoid.
        $row = array_change_key_case($row, CASE_LOWER);
        $name = isset($row['option_name']) && is_scalar($row['option_name'])
            ? (string)$row['option_name'] : '';
        $raw = isset($row['option_value']) && is_scalar($row['option_value'])
            ? (string)$row['option_value'] : '';
        if ($name === '' || $raw === '') {
            return null;
        }
        $parts = self::splitValue($raw);
        if ($parts === null) {
            return null;
        }
        return array(
            'option_name' => $name,
            'started_at_ms' => (int)$parts[0],
            'channel' => substr($parts[1], 0, 16),
            'action' => substr($parts[2], 0, 64),
            'pid' => ctype_digit($parts[3]) ? (int)$parts[3] : null,
            // A row from a build that predates phases has four parts. Reported
            // as an empty phase, which the census names explicitly, rather than
            // being confused with a request that reached no phase at all.
            'phase' => substr($parts[4], 0, 32),
            // A row with no timeline (older build, or an empty timeline) has
            // an empty one, not a rejected row: the request it describes is
            // still a competitor worth counting.
            'timeline' => $parts[5],
        );
    }
}
