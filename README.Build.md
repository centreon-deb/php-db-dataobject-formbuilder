Build instructions for the impatient


```bash
curl https://raw.githubusercontent.com/centreon-deb/php-db-dataobject-formbuilder/debian/bootstrap | sh
cd php-db-dataobject-formbuilder && git deb-pkg -C -U -u 1.0.2 -d origin/debian build
```

Further instruction in the [README.Build.md](README.Build.md) file.
