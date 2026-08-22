<?php

namespace App\Sync\Api;

use RuntimeException;

/**
 * S6-04 — thrown by HttpSyncApi in place of the generic RequestException a
 * 401 would otherwise produce, so SyncRunner can tell "this device was
 * revoked" apart from every other kind of failure and react to it
 * specifically (wipe local data) instead of just logging and backing off.
 */
class DeviceRevokedException extends RuntimeException {}
