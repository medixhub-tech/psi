<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('psi:integrations')->everyMinute()->withoutOverlapping(2);
