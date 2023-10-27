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
    wd_fee_max decimal(65,32) not null default 1000000
);

GRANT SELECT ON asset_network TO "wallet.io";

create table wallet_shards(
    netid varchar(32) not null,
    shardno int not null,
    deposit_warning text default null
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

create table wallet_transactions(
    xid bigserial not null primary key,
    uid bigint not null,
    type varchar(32) not null,
    assetid varchar(32) not null,
    netid varchar(32),
    amount decimal(65,32) not null,
    status varchar(32) not null,
    create_time timestamptz not null default current_timestamp,
    address varchar(255),
    memo varchar(255),
    exec_time timestamptz null default null,
    confirms int,
    confirms_target int,
    txid varchar(255),
    height bigint,
    wd_fee_this decimal(65,32),
    wd_fee_native decimal(65,32),
    bridge_issued smallint not null default 0,
    send_mail bool not null default TRUE,
    executor_lock bool not null default FALSE,
    wd_fee_base decimal(65,32) default null,
    lockid bigint default null,
    opposite_xid bigint default null
);

GRANT SELECT, INSERT, UPDATE ON wallet_transactions TO "wallet.io";
GRANT SELECT, USAGE ON wallet_transactions_xid_seq TO "wallet.io";
