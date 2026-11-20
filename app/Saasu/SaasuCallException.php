<?php

declare(strict_types=1);

namespace App\Saasu;

/**
 * A call that could not be answered, with a sentence saying why that an agent can act on.
 *
 * Its message goes into a tool result as it is, so it never carries a credential, a response body
 * that is not Saasu's own error text, or an arbitrary throwable's message.
 */
final class SaasuCallException extends \RuntimeException {}
