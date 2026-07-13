<?php

namespace Webimpian\LogCentral\Support;

class Scrubber
{
    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function scrub(array $data): array
    {
        $sensitive = array_map(strtolower(...), config('log-central.scrub', []));

        $scrubbed = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive(strtolower($key), $sensitive)) {
                $scrubbed[$key] = '[scrubbed]';

                continue;
            }

            $scrubbed[$key] = is_array($value) ? self::scrub($value) : $value;
        }

        return $scrubbed;
    }

    /**
     * @param  list<string>  $sensitive
     */
    private static function isSensitive(string $key, array $sensitive): bool
    {
        foreach ($sensitive as $needle) {
            if ($needle !== '' && str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
