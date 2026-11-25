# The oracle php floats with the CI image: its mysqlnd version must not fail a case.
s/string\([0-9]+\) "mysqlnd [0-9]+\.[0-9]+\.[0-9]+[^"]*"/string(N) "mysqlnd X.Y.Z"/g
s/mysqlnd [0-9]+\.[0-9]+\.[0-9]+[-A-Za-z0-9.]*/mysqlnd X.Y.Z/g
s/((client|driver)_version"?\]? *(=>|=) *)(int\()?[0-9]{5}/\1\4NNNNN/g
/(client|driver)_version"\]=>$/{
n
s/int\([0-9]{5}\)/int(NNNNN)/
}
