CREATE ROLE "wallet.io" LOGIN PASSWORD 'password';

create table networks(
    netid varchar(32) not null primary key,
    description varchar(64) not null,
    icon_url varchar(255) not null,
    native_assetid varchar(32) not null,
    confirms_target int not null,
    enabled boolean not null,
    memo_name varchar(32) default null,
    native_qr_format varchar(255) default null,
    token_qr_format varchar(255) default null,
    deposit_warning text default null,
    withdrawal_warning text default null,
    block_deposits_msg text default null,
    block_withdrawals_msg text default null
);

GRANT SELECT ON networks TO "wallet.io";

create table asset_network(
    assetid varchar(32) not null,
    netid varchar(32) not null,
    prec int not null,
    wd_fee_base decimal(65,32) not null,
    enabled boolean not null,
    contract varchar(255) default null,
    deposit_warning text default null,
    withdrawal_warning text default null,
    block_deposits_msg text default null,
    block_withdrawals_msg text default null,
    min_deposit decimal(65, 32) not null default 0,
    min_withdrawal decimal(65, 32) not null default 0,
    wd_fee_min decimal(65,32) not null default 1000000,
    wd_fee_max decimal(65,32) not null default 1000000,
    
    foreign key(assetid) references assets(assetid),
    foreign key(netid) references networks(netid)
);

GRANT SELECT ON asset_network TO "wallet.io";

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
