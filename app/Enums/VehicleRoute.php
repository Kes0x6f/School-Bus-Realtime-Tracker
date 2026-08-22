<?php

namespace App\Enums;

enum VehicleRoute: string
{
    case MANGALDAN = "Route A \u{2013} Mangaldan";
    case CALASIAO = "Route B \u{2013} Calasiao";
    case SAN_FABIAN = "Route C \u{2013} San Fabian";

    /**
     * Return the values accepted by route-writing endpoints.
     *
     * The application currently stores the display label in route_name. The
     * enum keeps that existing storage format while making the accepted set
     * server-owned and consistent across every writer.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $route): string => $route->value, self::cases());
    }
}
