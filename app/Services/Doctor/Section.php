<?php

namespace App\Services\Doctor;

/** One area the doctor examines. Rows are ['level' => ok|warn|fail, 'check' => …, 'detail' => …]. */
interface Section
{
    public function name(): string;

    /** @return list<array{level:string, check:string, detail:string}> */
    public function checks(): array;
}
