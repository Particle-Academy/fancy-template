<?php

declare(strict_types=1);

namespace FancyTemplate;

/**
 * The absent value. An enum case rather than a magic string because data can
 * contain any string -- a sentinel spelled in bytes is one a hostile value can
 * forge -- and nothing decoded from JSON is ever an enum.
 */
enum Absent
{
    case Value;
}
