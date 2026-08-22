<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * No DB, no auth — Connectivity (Sprint 3's client-side probe) needs to
 * tell "server unreachable" apart from "server up but database down", and
 * this route is deliberately the only one that can answer even when the
 * database is stopped.
 */
class PingController extends Controller
{
    public function show(): Response
    {
        return response()->noContent();
    }
}
