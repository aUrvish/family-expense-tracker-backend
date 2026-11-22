<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('rates:fetch')->daily();
Schedule::command('recurring:process')->daily();
