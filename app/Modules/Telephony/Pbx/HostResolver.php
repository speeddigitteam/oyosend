<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Pbx;

/** Resolves a host name to its IP addresses. A seam, so tests can answer without the network. */
class HostResolver
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        $v4 = @gethostbynamel($host);

        if (is_array($v4)) {
            array_push($addresses, ...$v4);
        }

        $v6 = @dns_get_record($host, DNS_AAAA);

        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
