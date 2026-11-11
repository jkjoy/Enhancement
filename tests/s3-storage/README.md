# S3 storage regression

Run `php tests/s3-storage/run.php` with PHP 8+, cURL, GD/WebP and subprocess creation enabled.

The suite promotes every PHP warning, notice and deprecation to an exception, including suppressed diagnostics. It tests compression success, unsupported and corrupt images, GD write failures, upload exceptions and errors, preservation of source files, temporary-file cleanup and reflection hook cleanup. Real cURL PUT/DELETE requests use a disposable server on `127.0.0.1`; a stream callback exception and failed HTTP/connection responses verify that upload streams close in every case. A private temporary directory isolates all test files. No installed Typecho configuration, database or external storage is accessed.
