# PKP plugin CLI usage

## Background
Problem: how do we interact with the [PKP Plugin Gallery](https://docs.pkp.sfu.ca/learning-ojs/en/settings-website#plugin-gallery) outside of the UI?  The correct way to approach this is to abstract the [plugin gallery operations](https://github.com/pkp/pkp-lib/blob/9f48cbe60d4471a23ffd8a1a46eb1a7e0986c027/controllers/grid/plugins/PluginGalleryGridHandler.inc.php#L271-L341) into a service class which would be reusable across the UI and the CLI, but we're not there yet.  Instead, let's cheat.

## CLI tool
https://github.com/pkp/pkp-lib/blob/stable-3_3_0/tools/plugins.php

This provides a new utility with minimal options:
```
$ sudo -u apache php lib/pkp/tools/plugins.php help
Plugin Gallery tool
Usage: lib/pkp/tools/plugins.php action [arguments]
  Actions:
        list [search]: show latest compatible plugin(s). Optional "search" text against plugin class
        info path: show detail for plugin identified by "path", such as generic/pln
```

For example, any plugin with the keyword "article":
```
$ sudo -u apache php lib/pkp/tools/plugins.php list article
plugins/generic/epubJsViewer https://github.com/EKT/epubJsViewer-ojs/releases/download/v1_0_0-0/epubJsViewer-ojs-v1_0_0-0.tar.gz noInstalledVersion
plugins/generic/hypothesis https://github.com/asmecher/hypothesis/releases/download/v1.0.3-2/hypothesis-v1.0.3-2.tar.gz installedVersionNewest
plugins/generic/coins https://github.com/pkp/coins/releases/download/v1.0.3-2/coins-v1.0.3-2.tar.gz installedVersionNewest
plugins/blocks/keywordCloud https://github.com/lepidus/keywordCloud/releases/download/v1.1.0.5/keywordCloud.tar.gz installedVersionOlder
plugins/generic/texture https://github.com/pkp/texture/releases/download/v2_4_3-3/texture-v2_4_3-3.tar.gz installedVersionNewer
plugins/generic/paperbuzz https://github.com/pkp/paperbuzz/releases/download/v1_0_2-0/paperbuzz-v1_0_2-0.tar.gz installedVersionNewest
plugins/generic/plumAnalytics https://github.com/ulsdevteam/ojs-plum-plugin/releases/download/v1.4.0-0/plumAnalytics-1.4.0-0.tgz installedVersionNewest
plugins/generic/citations https://github.com/RBoelter/citations/releases/download/v3_2_0-3/citations-v3_2_0-3.tar.gz noInstalledVersion
plugins/generic/browseBySection https://github.com/pkp/browseBySection/releases/download/v1_1_0_0/browseBySection-v1_1_0_0.tar.gz installedVersionNewest
```

Our output will be the plugin path, the latest plugin tarball, and the current status of the plugin (as stolen from the end of the [locale keys](https://github.com/pkp/pkp-lib/blob/559edb8599e688c1f520a9d68d8ac526f90081b4/controllers/grid/plugins/PluginGalleryGridHandler.inc.php#L209-L236))

## Making some (workaround) magic

Note that these shell scripts are supplied for illustration only.  For example, these assume that your webserver user is `apache` and that you are running as a user able to write to the OJS source, and able to `sudo` to `apache`.

You will almost certainly want to change the file permissions on the extracted plugin to match your webserver environment.

### Install the latest version of all missing plugins
```
sudo -u apache php lib/pkp/tools/plugins.php list | grep noInstalledVersion | \
while read plugin
do
  pluginpath=`echo $plugin | cut -d' ' -f1`
  pluginsrc=`echo $plugin | cut -d' ' -f2`
  echo $pluginpath
  if [[ -e $pluginpath ]]
  then
    rm -r $pluginpath
  fi
  wget -q -O /tmp/plugin.tmp.tgz $pluginsrc
  tar -xzf /tmp/plugin.tmp.tgz -C `dirname $pluginpath`
  sudo -u apache php lib/pkp/tools/installPluginVersion.php $pluginpath/version.xml
done
```

### Or, upgrade all plugins:
```
sudo -u apache php lib/pkp/tools/plugins.php list | grep installedVersionOlder | \
while read plugin
do
  pluginpath=`echo $plugin | cut -d' ' -f1`
  pluginsrc=`echo $plugin | cut -d' ' -f2`
  echo $pluginpath
  rm -r $pluginpath
  wget -q -O /tmp/plugin.tmp.tgz $pluginsrc
  tar -xzf /tmp/plugin.tmp.tgz -C `dirname $pluginpath`
done
sudo -u apache php tools/upgrade.php upgrade
```

