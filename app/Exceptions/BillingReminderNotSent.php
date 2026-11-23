<?php

namespace App\Exceptions;

/** A transport adapter may use this only when it can prove no message was accepted. */
final class BillingReminderNotSent extends \RuntimeException {}
