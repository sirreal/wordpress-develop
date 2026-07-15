#!/usr/bin/env php
<?php

// Deliberately produces no result so the Common Crawl supervisor must retain
// the already-persisted input and synthesize a worker-timeout finding.
usleep( 500000 );
