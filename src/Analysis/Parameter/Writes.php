<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Analysis\Parameter;

final class Writes
{
    private int $sequence = 0;

    /** @var list<Write> */
    private array $writes = [];

    /**
     * @param  'empty'|'zero'|'truthy'|'dynamic'  $value
     */
    public function add(string $name, string $value, ?string $literal): void
    {
        $this->writes[] = new Write(++$this->sequence, $name, $value, $literal);
    }

    public function addUnknown(): void
    {
        $this->writes[] = self::unknown(++$this->sequence);
    }

    /** @return list<Write> */
    public function all(): array
    {
        return $this->writes;
    }

    public static function unknown(int $sequence = 1): Write
    {
        return new Write($sequence, null, 'dynamic', null);
    }
}
