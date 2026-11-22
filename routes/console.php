<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('rates:fetch')->daily();
Schedule::command('recurring:process')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
