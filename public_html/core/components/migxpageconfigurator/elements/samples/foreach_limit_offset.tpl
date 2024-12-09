##foreach subject as $item^ index=$i^ last=$l^}
    ##set $c^ = 0}
    ##if $i^ >= offset AND $c^ < limit}
        ##set $c^ = $c^ %2B 1}
        html
    ##/if}
##/foreach}
