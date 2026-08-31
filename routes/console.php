<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\CleanExpiredConversationsJob;

/*
|--------------------------------------------------------------------------
| Console Routes / Scheduler
|--------------------------------------------------------------------------
*/

Schedule::job(new CleanExpiredConversationsJob)->hourly();
