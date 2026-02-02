# Rough Idea

Work on active format reconstruction and reparenting support in the WP_HTML_Processor class.

## Key Points

- Note cases where the `bail()` method is used
- Rely on unit tests and the html5lib test suite
- Run tests with: `./vendor/bin/phpunit -c tests/phpunit/tests/html-api/phpunit.xml --group=html-api-html5lib-tests`
- Access the html5lib-tests/tree-construction tests to identify relevant tests
