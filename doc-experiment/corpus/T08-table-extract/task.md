# Extract table data

Write a single PHP function:

```php
function table_to_array( string $html ): array
```

Given an HTML fragment (as found inside `<body>`), find the first `TABLE`
element and return its contents as a list of rows; each row is a list of
its cells' text content in order. Both `TD` and `TH` cells count. A cell's
text content is the concatenation of all text nodes inside it, character
references decoded, markup contributing nothing.

Handle ordinary HTML table structure as a browser would. You may assume
tables are not nested. Return an empty array when there is no table.

Example:

```php
table_to_array( '<table><tr><th>Name</th><th>Age</th></tr><tr><td>Ada</td><td>36</td></tr></table>' )
// => [ ['Name', 'Age'], ['Ada', '36'] ]
```
