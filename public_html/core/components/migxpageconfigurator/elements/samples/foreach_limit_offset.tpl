##foreach subject as $item^ index=$i^ last=$l^}
    ##set $c^ = 0}
    ##if $i^ >= offset AND $c^ &lt; limit}
        ##set $c^ = $c^ + 1}
        html
    ##/if}
##/foreach}
