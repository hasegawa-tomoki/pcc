#!/bin/bash
if [ $# -eq 0 ]; then
  echo "Usage: $0 [clean|test]"
  exit 1
fi

case "$1" in
  clean)
    cd ctests && make clean
    ;;
  test)
    cd ctests && make clean && make test
    ;;
  *)
    echo "Usage: $0 [clean|test]"
    exit 1
    ;;
esac