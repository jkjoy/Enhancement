# Package operations regression

Run `php tests/package-operations/run.php` with PHP 8+, ZipArchive and subprocess creation enabled.

The suite copies the production action and helpers into temporary sites. It uses real ZIP archives and symbolic links to check package deletion protection, traversal and link rejection, safe update cleanup, normal updates, linked deployment refusal and both go reminder routes. No installed Typecho configuration, database or remote service is accessed. Temporary sites are removed when the run completes.
