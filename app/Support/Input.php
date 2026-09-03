<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Support;

/**
 * Guards for values read out of a decoded JSON request body.
 *
 * A JSON body's shape belongs to the caller, not to us: nothing stops a
 * client from sending `{"pin": ["1","2"]}` where a string is expected, and
 * every endpoint that fed such a field straight into trim(),
 * password_verify() or a string-typed parameter died with an uncaught
 * TypeError — an HTTP 500 with a stack trace in the error log, triggerable
 * by any visitor on the login, name-check, leaderboard-submit, share-token
 * and setup endpoints. Not an authentication bypass (the fatal
 * happens before anything is evaluated), but a crash on attacker-shaped
 * input is a defect regardless, and the noise it writes to the log buries
 * the SECURITY: lines that matter.
 *
 * The rule here mirrors what PHP itself does with scalars: an internal
 * function in coercive mode accepts an int or a bool where a string is
 * expected (`trim(123)` is "123"), so scalars keep working exactly as they
 * always have — only the types PHP would have fataled on (array, object,
 * null stays the default) become the default instead.
 *
 * Deliberately NOT used for fields where an empty string is a command:
 * `admin/set-deadline` treats '' as "clear the deadline", so silently
 * coercing a malformed value to '' there would turn a broken request into a
 * destructive one. Such endpoints reject non-strings explicitly instead.
 */
final class Input
{
    /**
     * The value as a string, or $default when it has no faithful string form.
     */
    public static function string(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }
}
