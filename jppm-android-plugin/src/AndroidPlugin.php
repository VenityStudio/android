<?php
use packager\{
    cli\Console, Event, JavaExec, Packager, Vendor
};
use php\io\Stream;
use php\lib\fs;
use compress\ZipArchive;
use compress\ZipArchiveEntry;

/**
 * Class AndroidPlugin
 * @jppm-task-prefix android
 *
 * @jppm-task build_apk as apk
 * @jppm-task build_adb as apk-adb
 * @jppm-task build_release as apk-release
 * @jppm-task init as init
 */
class AndroidPlugin
{
    public function init(Event $e)
    {
        $packageData = $e->package()->toArray();

        if (!isset($packageData['deps']['jphp-android-ext'])) {
            Console::log('-> installing android extension ...');

            Tasks::run('add', [
                'jphp-android-ext'
            ]);
        }

        Console::log('-> install gradle ...');

        (new GradlePlugin($e))->install($e);

        Console::log("-> prepare jphp compiler ...");

        fs::makeDir('./.venity/');
        fs::makeFile('./.venity/compiler.jar');

        Stream::putContents('./.venity/compiler.jar', Stream::getContents("res://compiler.jar"));
    }

    public function generateBuild(string $file, array $androidData)
    {
        Console::log('-> generate build.gradle file ...');

	$buildScript = "buildscript {
    repositories {
        jcenter()
    }
    dependencies {
        classpath 'org.javafxports:jfxmobile-plugin:1.3.10'
    }
}
apply plugin: 'org.javafxports.jfxmobile'
repositories {
    maven {
        name 'gulon'
        url \"http://nexus.gluonhq.com/nexus/content/repositories/releases/\" // gluon repository
    }
    mavenLocal()
    jcenter()
  
}
dependencies {
    androidRuntime 'com.gluonhq:charm-down-core-android:3.5.0'
    compile files('build/{$file}')
}
jfxmobile {
    javafxportsVersion = '8.60.9'
    downConfig {
        version = '3.8.0'
        plugins 'display', 'lifecycle', 'statusbar', 'storage'
    }
    android {
        compileSdkVersion  = {$androidData['sdk']}
        buildToolsVersion  = '{$androidData['sdk-tools']}'
        applicationPackage = '{$androidData['package-name']}'
    }
}
mainClassName = \"org.venity.jphp.ext.android.UXAndroidApplication\"";

	    if (fs::isFile("./build.coustom.gradle"))
	        $buildScript .= Stream::getContents("./build.coustom.gradle");

        Stream::putContents('./build.gradle', $buildScript);
    }

    public function gradle_build(string $task, Event $event)
    {
        $this->init($event);

        Console::log('-> starting build jar ...');

        Tasks::run('build');

        $androidData = $event->package()->toArray()['android'];
        $buildFileName = "{$event->package()->getName()}-{$event->package()->getVersion('last')}";

        Console::log('-> unpack jar');

        fs::makeDir('./build/out');

        $zip = new ZipArchive(fs::abs('./build/' . $buildFileName . '.jar'));
        $zip->readAll(function (ZipArchiveEntry $entry, ?Stream $stream) {
            if (!$entry->isDirectory()) {
                fs::makeFile(fs::abs('./build/out/' . $entry->name));
                fs::copy($stream, fs::abs('./build/out/' . $entry->name));
                echo '.';
            } else fs::makeDir(fs::abs('./build/out/' . $entry->name));
        });
        echo ". done\n";
	    
        Console::log('-> starting compiler ...');

        $process = new \php\lang\Process([
            'java', '-jar', './.venity/compiler.jar',
            '--src', './build/out',
            '--dest', './build/compile.jar'
        ], './');

        $exit = $process->inheritIO()->startAndWait()->getExitValue();

        if ($exit != 0) {
            Console::log("[ERROR] Error compiling jphp");
            exit($exit);
        } else Console::log(" -> done");

        $this->generateBuild('compile.jar', $androidData);
        
        Console::log('-> starting gradle ...');

        /** @var \php\lang\Process $process */
        $process = (new GradlePlugin($event))->gradleProcess([
            $task
        ])->inheritIO()->startAndWait();

        exit($process->getExitValue());
    }

    public function build_apk(Event $event)
    {
        $this->gradle_build('android', $event);
    }

    public function build_adb(Event $event)
    {
        $this->gradle_build('androidInstall', $event);
    }

    public function build_release(Event $event)
    {
        $this->gradle_build('androidRelease', $event);
    }
}