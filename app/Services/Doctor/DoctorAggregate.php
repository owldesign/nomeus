<?php

namespace App\Services\Doctor;

use App\Services\ServiceDoctor;

/** Every section in order; each row tagged with its section. */
final class DoctorAggregate
{
    /** @var list<Section> */
    private array $sections;

    public function __construct(
        ValetDoctor $valet,
        PhpDoctor $php,
        SelfDoctor $self,
        ServiceDoctor $services,
        DumpsDoctor $dumps,
        MailDoctor $mail,
        RetentionDoctor $retention,
    ) {
        $servicesSection = new class($services) implements Section
        {
            public function __construct(private readonly ServiceDoctor $doctor) {}

            public function name(): string
            {
                return 'services';
            }

            public function checks(): array
            {
                return $this->doctor->checks();
            }
        };
        $this->sections = [$valet, $php, $self, $servicesSection, $dumps, $mail, $retention];
    }

    /** @return list<string> */
    public function sectionNames(): array
    {
        return array_map(fn (Section $s) => $s->name(), $this->sections);
    }

    /**
     * @return array{rows: list<array{section:string, level:string, check:string, detail:string}>, counts: array{ok:int, warn:int, fail:int}}
     */
    public function run(?string $only = null): array
    {
        $rows = [];
        foreach ($this->sections as $section) {
            if ($only !== null && $section->name() !== $only) {
                continue;
            }
            try {
                foreach ($section->checks() as $row) {
                    $rows[] = ['section' => $section->name()] + $row;
                }
            } catch (\Throwable $e) {
                $rows[] = ['section' => $section->name(), 'level' => 'fail', 'check' => 'doctor', 'detail' => 'check crashed: '.$e->getMessage()];
            }
        }
        $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($rows as $row) {
            $counts[$row['level']] = ($counts[$row['level']] ?? 0) + 1;
        }

        return ['rows' => $rows, 'counts' => $counts];
    }
}
