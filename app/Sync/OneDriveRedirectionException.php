<?php

namespace App\Sync;

use RuntimeException;

/**
 * S7-02 — thrown by LocalDatabasePath::assertNotOneDriveRedirected() when
 * %LOCALAPPDATA% has been Known-Folder-Move redirected into a
 * OneDrive-synced folder. A distinct type so this specific, actionable
 * failure is never mistaken for DeviceIdentity's "no SYNC_DEVICE_ID"
 * boot failure it's checked alongside.
 */
class OneDriveRedirectionException extends RuntimeException {}
