<?php

namespace App\Modules\Activity\Services;

use App\Models\User;
use App\Modules\Activity\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Device identity over HTTP.
 *
 * A MAC address cannot be read from a browser request — nothing in HTTP carries one, and anything claiming to
 * would be a guess. Oracle solved the same problem by writing a `COMPUTER VARCHAR2(15)` on each row, filled in
 * by the client. This does the same: the server issues an opaque id on first visit and stores it in a
 * long-lived cookie, and the operator registers a human name for that machine once.
 *
 * The cookie is encrypted (Laravel's EncryptCookies runs over it, and it is not in the `except` list), so it is
 * authenticated as well as opaque: a tampered value fails the MAC and is dropped before it reaches here, and a
 * forged one would only invent a new device row, never impersonate an existing one — it grants nothing.
 */
final class DeviceRegistrar
{
    public const COOKIE = 'device_id';

    /** Ten years: a mill terminal keeps its identity across shifts, updates and browser restarts. */
    public const COOKIE_MINUTES = 60 * 24 * 365 * 10;

    /** How stale `last_seen_at` may get before a request is allowed to cost a write. */
    public const TOUCH_AFTER_MINUTES = 5;

    private ?UserDevice $resolved = null;

    private bool $tracked = false;

    /**
     * What TrackDevice calls on every web request, guests included.
     *
     * It issues and refreshes the cookie for anyone, but it creates a `user_devices` row only once there is a
     * signed-in user. Without that condition every cookieless request — a crawler, a health check, a curl loop
     * against a public route — mints a row (and, when the model was audited on create, a second one in
     * `audits`): unauthenticated, unbounded table growth with no rate limit and nothing to dedupe on.
     *
     * A guest still leaves with the cookie, so the terminal keeps the same identity into the session where it
     * finally matters; the row is created at that point, under the id the cookie already carries.
     */
    public function track(Request $request): ?UserDevice
    {
        $this->tracked = true;

        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $deviceId = $this->issue($request);

        if ($request->user() === null) {
            $device = UserDevice::query()->where('device_id', $deviceId)->first();

            if ($device !== null) {
                $this->touch($device, $request);
                $this->resolved = $device;
            }

            return $this->resolved;
        }

        return $this->resolved = $this->store($deviceId, $request);
    }

    /**
     * The device this request came from, creating the row if this is the first time it has been seen. Used
     * where a row is required — naming the terminal, and stamping an action on it — never for a bare page view
     * by an anonymous caller.
     */
    public function resolve(Request $request): UserDevice
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        return $this->resolved = $this->store($this->issue($request), $request);
    }

    /**
     * The id for this request's terminal: the cookie when it carries one this application issued, otherwise a
     * fresh ULID. Either way the cookie is (re)queued, so the browser keeps the identity.
     *
     * Only ULIDs this application issued are accepted; anything else starts a new device rather than letting a
     * caller choose its own identifier.
     */
    private function issue(Request $request): string
    {
        $cookie = $request->cookie(self::COOKIE);
        $deviceId = is_string($cookie) && $cookie !== '' && Str::isUlid($cookie) ? $cookie : (string) Str::ulid();

        if ($cookie !== $deviceId) {
            Cookie::queue(Cookie::make(self::COOKIE, $deviceId, self::COOKIE_MINUTES, httpOnly: true, sameSite: 'lax'));
        }

        return $deviceId;
    }

    /** The row for an id, created on first sight and kept current afterwards. */
    private function store(string $deviceId, Request $request): UserDevice
    {
        $user = $request->user();
        $now = now();

        $device = UserDevice::query()->firstOrCreate(
            ['device_id' => $deviceId],
            [
                'user_id' => $user?->getKey(),
                'last_ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ],
        );

        if (! $device->wasRecentlyCreated) {
            $this->touch($device, $request);
        }

        return $device;
    }

    /** The device already resolved for this request, or null when TrackDevice has not run (console, queue). */
    public function current(): ?UserDevice
    {
        return $this->resolved;
    }

    /**
     * Whether TrackDevice ran, i.e. whether there is a terminal behind this at all. False on the console and
     * in queue workers — and in a seeder, which is what stops a seeded action from inventing a device row.
     */
    public function wasTracked(): bool
    {
        return $this->tracked;
    }

    /** Register (or change) the human name of a device — Oracle's COMPUTER. */
    public function name(UserDevice $device, string $computerName, ?User $user = null): UserDevice
    {
        $device->forceFill([
            'computer_name' => $computerName,
            'user_id' => $user?->getKey() ?? $device->user_id,
        ])->save();

        return $device;
    }

    /**
     * Keep last_seen_at / last_ip / user fresh without writing on every single request: a busy screen would
     * otherwise turn one page into dozens of updates on the same row.
     */
    private function touch(UserDevice $device, Request $request): void
    {
        $user = $request->user();
        $stale = $device->last_seen_at->diffInMinutes(now()) >= self::TOUCH_AFTER_MINUTES;
        $newUser = $user !== null && $device->user_id !== $user->getKey();

        if (! $stale && ! $newUser) {
            return;
        }

        $device->forceFill([
            'user_id' => $user?->getKey() ?? $device->user_id,
            'last_ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'last_seen_at' => now(),
        ])->saveQuietly();
    }
}
