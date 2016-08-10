# php-db-dataobject-formbuilder Debian package build

This repository allows you to build Debian packages for the
[php-db-dataobject-formbuilder](https://github.com/centreon/php-db-dataobject-formbuilder).

## How to build in 3 commands

First you need to install
[git-deb-pkg](https://github.com/renard/git-deb-pkg) git plugging that will
help you to build the package. Then you just need to run:

    git clone https://github.com/centreon-deb/php-db-dataobject-formbuilder.git
    cd php-db-dataobject-formbuilder/
    git deb-pkg -C -U -u 1.0.2 -d origin/debian build

## How to build the hard way

If you don't want to install *git-deb-pkg* you can run following and you
want to build the last version of the Debian package (1.1.1 at time of
writing), you need to run:

    git clone https://github.com/centreon-deb/php-db-dataobject-formbuilder.git
    cd php-db-dataobject-formbuilder/
	git checkout -b build 1.0.2
	git checkout origin/debian -- ./debian
	git archive --format=tar --prefix="$(basename `pwd`).orig/" \
        1.0.2 | gzip > "../$(basename `pwd`)_1.0.2.orig.tar.gz"
	debuild -I.git -i'\.git/'

Once the package is built, you can clean the repository and switch back to
the *master* branch:

	debuild clean
	git reset --hard HEAD
	git checkout master

Are you sure you don't want to install *deb-git-pkg*?

# Copyright

Copyright © 2016 Sébastien Gross \<seb•ɑƬ•chezwam•ɖɵʈ•org\>.

Released under WTFPL (http://sam.zoy.org/wtfpl/COPYING).

Feel free to contact me if you want to participate.

Follow me on twitter https://twitter.com/renard_0

# Links

- http://pear.php.net/package/DB_DataObject_FormBuilder
