#!/bin/bash
if [ $# -eq 0 ]; then
  echo "Usage: $0 [clean|test] [filename.c (optional)]"
  exit 1
fi

CMD="$1"
FILE="$2"

case "$CMD" in
  clean)
    cd ctests && make clean
    ;;
  test)
    if [ -n "ctests/test/$FILE" ]; then
      cd ctests && make clean && make test FILE="$FILE"
    else
      cd ctests && make clean && make test
    fi
    ;;
  *)
    echo "Usage: $0 [clean|test] [filename.c (optional)]"
    exit 1
    ;;
esac