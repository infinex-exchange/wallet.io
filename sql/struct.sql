create table wallet_shards(
    netid varchar(32) not null,
    shardno int not null,
    deposit_warning text default null,
    block_deposits_msg text default null,
    block_withdrawals boolean not null default FALSE
);

GRANT SELECT ON wallet_shards TO "wallet.io";

create table deposit_addr(
    addrid bigserial not null primary key,
    netid varchar(32) not null,
    shardno int not null,
    address varchar(255) not null,
    memo varchar(255) default null,
    uid bigint default null
);

GRANT SELECT, INSERT, UPDATE ON deposit_addr TO "wallet.io";

create table wallet_nodes(
    nodeid bigserial not null,
    netid varchar(32) not null,
    shardno int not null,
    last_ping timestamptz not null default to_timestamp(0)
);

GRANT SELECT, UPDATE ON wallet_nodes TO "wallet.io";
