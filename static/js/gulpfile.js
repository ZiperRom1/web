(function () {
    'use strict';

    var gulp            = require('gulp'),
        bower           = require('gulp-bower'),
        mainBowerFiles  = require('main-bower-files'),
        del             = require('del'),
        shell           = require('gulp-shell');

    gulp.task('build', shell.task('node ./node_modules/requirejs/bin/r.js -o app.build.js'));


    gulp.task('bower_install', function () {
        return bower();
    });

    gulp.task('bower_move', ['bower_install'], function () {
        return gulp.src(mainBowerFiles()).pipe(gulp.dest('lib/vendor'));
    });

    gulp.task('bower_clean', ['bower_move'], function () {
        del(['lib/vendor/*', '!lib/vendor/*.js']);
    });

    gulp.task('default', ['bower_install', 'bower_move', 'bower_clean']);
}());
