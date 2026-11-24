<?php
// LAB 7 (NEW FILE) - prints the before/after table from evidence/lab7/bench_*_index.json and writes the CSV.
$b = json_decode(file_get_contents(__DIR__ . '/../evidence/lab7/bench_before_index.json'), true);
$a = json_decode(file_get_contents(__DIR__ . '/../evidence/lab7/bench_after_index.json'), true);
$csv = "query,before_median_ms,after_median_ms,before_p95_ms,after_p95_ms,speedup_median\n";
printf("%-74s %11s %11s %11s %11s %9s\n", 'Query', 'before med', 'after med', 'before p95', 'after p95', 'speed-up');
foreach ($b as $name => $x) {
    $y = $a[$name]; $sp = $x['median'] / max($y['median'], 0.0001);
    printf("%-74s %9.3f ms %9.3f ms %9.3f ms %9.3f ms %8.1fx\n", substr($name, 0, 74), $x['median'], $y['median'], $x['p95'], $y['p95'], $sp);
    $csv .= sprintf("\"%s\",%.3f,%.3f,%.3f,%.3f,%.1f\n", $name, $x['median'], $y['median'], $x['p95'], $y['p95'], $sp);
}
file_put_contents(__DIR__ . '/../evidence/lab7/benchmark_summary.csv', $csv);
