<?php

namespace Zofe\Ai\Services;

use RuntimeException;

/** The personal data filter could not do its job: the call to the AI provider was not made. */
class AiRedactionException extends RuntimeException {}
