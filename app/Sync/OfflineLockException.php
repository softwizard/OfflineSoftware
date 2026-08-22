<?php

namespace App\Sync;

use RuntimeException;

/**
 * S6-03 — thrown by Syncable::guardOfflineLock() when a local write is
 * attempted past max_offline_days. A distinct type (rather than a generic
 * exception) so a Livewire component can catch it and show the message
 * as a toast instead of a 500.
 */
class OfflineLockException extends RuntimeException {}
