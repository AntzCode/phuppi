#!/bin/bash

rm -rf aws
mkdir aws
cp aws.zip aws
cd aws
unzip aws.zip

recurseDelete() {
 for i in "$1"/*;do
    if [ -d "$i" ];then
        if grep -Fxq "$i" ../whitelist.txt
        then
            echo "keep: $i"
        else
            echo "delete: $i"
            rm -rf "$i";
        fi
        if grep -Fxq "$i" ../blacklist.txt
        then
            echo "delete: $i"
            rm -rf "$i";
        fi
        recurseDelete "$i"
    elif [ -f "$i" ]; then
        if grep -Fxq "$i" ../whitelist.txt
        then
            echo "keep: $i"
        else
            echo "delete: $i"
            rm "$i"
        fi
        if grep -Fxq "$i" ../blacklist.txt
        then
            echo "delete: $i"
            rm "$i"
        fi
    fi
 done
}

recurseDelete "."

