# CSS Selector Performance Tests

This directory contains performance tests for the WordPress HTML API CSS selector parsing functionality.

## Test Files

### wpCssSelectorPerformance.php
Comprehensive performance tests that measure execution time for various CSS selector parsing scenarios:

- **Type selectors**: `div`, `span`, `article`, etc.
- **Class selectors**: `.class`, `.my-class`, etc.
- **ID selectors**: `#id`, `#my-id`, etc.
- **Attribute selectors**: `[attr]`, `[attr="value"]`, etc.
- **Compound selectors**: `div.class#id[attr]`
- **Complex selectors**: `div > p`, `nav ul li`
- **Selector lists**: `div, p, span`
- **Unicode selectors**: `.café`, `#résumé`, etc.
- **Escaped selectors**: `.\\31 23`, `#\\2e test`
- **Large selectors**: Complex selectors with many parts
- **Malformed selectors**: Error handling performance

### wpCssSelectorBenchmark.php
Focused benchmark tests that measure throughput (operations per second) and memory usage:

- **Throughput tests**: How many selectors can be parsed per second
- **Memory scaling**: How memory usage scales with selector complexity
- **Error handling**: Performance of parsing invalid selectors
- **Real-world scenarios**: Performance with typical framework/theme selectors

## Running Performance Tests

### Run All Performance Tests
```bash
vendor/bin/phpunit tests/phpunit/tests/html-api/wpCssSelectorPerformance.php
vendor/bin/phpunit tests/phpunit/tests/html-api/wpCssSelectorBenchmark.php
```

### Run Specific Test Groups
```bash
# Run only performance tests
vendor/bin/phpunit --group performance

# Run only benchmark tests
vendor/bin/phpunit --group benchmark

# Run both performance and benchmark tests
vendor/bin/phpunit --group performance,benchmark
```

### Run Individual Tests
```bash
# Run a specific performance test
vendor/bin/phpunit --filter test_compound_selector_parsing_performance tests/phpunit/tests/html-api/wpCssSelectorPerformance.php

# Run a specific benchmark test
vendor/bin/phpunit --filter test_simple_selector_parsing_throughput tests/phpunit/tests/html-api/wpCssSelectorBenchmark.php
```

## Understanding Results

### Performance Test Results
Performance tests output timing information to error_log:
```
Performance [Type Selector Parsing]: avg=0.05ms, min=0.03ms, max=0.12ms, iterations=1000
```

- **avg**: Average execution time in milliseconds
- **min**: Fastest execution time
- **max**: Slowest execution time
- **iterations**: Number of test iterations

### Benchmark Test Results
Benchmark tests output throughput and memory information:
```
[BENCHMARK] Simple Selector Parsing: 67543.21 ops/sec, 0.0148ms avg, 0.00KB memory, 0.00KB peak
```

- **ops/sec**: Operations (parsings) per second
- **avg**: Average time per operation in milliseconds
- **memory**: Memory used during test in KB
- **peak**: Peak memory usage in KB

## Performance Expectations

### Current Performance Thresholds
- **Simple selectors**: > 50,000 ops/sec
- **Compound selectors**: > 10,000 ops/sec
- **Complex selectors**: > 5,000 ops/sec
- **Selector lists**: > 3,000 ops/sec
- **Attribute selectors**: > 8,000 ops/sec
- **Unicode selectors**: > 15,000 ops/sec
- **Error handling**: > 20,000 ops/sec

### Memory Usage
- Memory usage should scale roughly linearly with selector complexity
- Error handling should not consume excessive memory
- Memory leaks should be avoided during repeated parsing

## Monitoring Performance

### In CI/CD
These tests can be integrated into CI/CD pipelines to:
- Monitor performance regressions
- Ensure performance standards are maintained
- Compare performance across different PHP versions

### Local Development
Use these tests to:
- Measure performance impact of code changes
- Identify performance bottlenecks
- Optimize parsing algorithms

## Troubleshooting

### Tests Failing Due to Performance
If performance tests fail consistently:

1. **Check system load**: High CPU usage can affect timing
2. **Run multiple times**: Single runs may have variance
3. **Update thresholds**: Hardware differences may require adjustment
4. **Profile specific cases**: Use tools like Xdebug to identify bottlenecks

### Adjusting Performance Thresholds
Thresholds can be adjusted in the test files:
- `wpCssSelectorPerformance.php`: Modify `PERFORMANCE_THRESHOLD_MS` constant
- `wpCssSelectorBenchmark.php`: Modify assertion values in individual tests

### Adding New Performance Tests
When adding new selector functionality:
1. Add parsing performance tests to `wpCssSelectorPerformance.php`
2. Add throughput benchmarks to `wpCssSelectorBenchmark.php`
3. Update this documentation with new performance expectations

## Best Practices

### Writing Performance Tests
- Use realistic test data that represents actual usage
- Include both positive and negative test cases
- Test edge cases and error conditions
- Consider memory usage alongside execution time

### Interpreting Results
- Look for trends over time, not just individual runs
- Compare relative performance between different selector types
- Consider the context of how selectors are used in real applications
- Monitor both average and peak performance metrics

### Optimization Guidelines
Based on test results, focus optimization efforts on:
- Most commonly used selector types
- Cases with highest memory usage
- Scenarios with slowest throughput
- Error handling paths that are frequently exercised