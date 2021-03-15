<?php

namespace app\data\service;

use think\admin\Service;

/**
 * 用户等级升级服务
 * Class UserUpgradeService
 * @package app\data\service
 */
class UserUpgradeService extends Service
{

    /**
     * 获取用户等级数据
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function levels(): array
    {
        $query = $this->app->db->name('DataUserUpgrade');
        return $query->where(['status' => 1])->order('number asc')->select()->toArray();
    }

    /**
     * 尝试绑定上级代理
     * @param integer $uid 用户UID
     * @param integer $pid 代理UID
     * @param boolean $force 正式绑定
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function bindAgent(int $uid, int $pid = 0, bool $force = true): array
    {
        $user = $this->app->db->name('DataUser')->where(['id' => $uid])->find();
        if (empty($user)) return [0, '用户查询失败'];
        if (!empty($user['pids'])) return [0, '已绑定推荐人'];
        // 检查代理用户
        if (empty($pid)) $pid = $user['pid0'];
        if (empty($pid)) return [0, '绑定推荐人不存在'];
        if ($uid == $pid) return [0, '推荐人不能是自己'];
        $parant = $this->app->db->name('DataUser')->where(['id' => $pid])->find();
        if (empty($parant['pids']) || empty($parant['vip_code'])) return [0, '推荐人无推荐资格'];
        if (stripos($parant['path'], "-{$uid}-") !== false) return [0, '不能绑定下属'];
        // 组装代理数据
        $path = rtrim($parant['path'] ?: '-', '-') . "-{$parant['id']}-";
        $data = [
            'pid0' => $parant['id'], 'pid1' => $parant['id'], 'pid2' => $parant['pid1'],
            'pids' => $force ? 1 : 0, 'path' => $path, 'layer' => substr_count($path, '-'),
        ];
        // 更新用户代理
        if ($this->app->db->name('DataUser')->where(['id' => $uid])->update($data) !== false) {
            return [1, '绑定代理成功'];
        } else {
            return [0, '绑定代理失败'];
        }
    }

    /**
     * 同步刷新用户返利
     * @param integer $uuid
     * @return array [total, count, lock]
     * @throws \think\db\exception\DbException
     */
    public function syncRebate(int $uuid): array
    {
        $total = abs($this->app->db->name('DataUserRebate')->where("uid='{$uuid}' and status=1 and amount>0 and deleted=0")->sum('amount'));
        $count = abs($this->app->db->name('DataUserRebate')->where("uid='{$uuid}' and status=1 and amount<0 and deleted=0")->sum('amount'));
        $lockd = abs($this->app->db->name('DataUserRebate')->where("uid='{$uuid}' and status=0 and amount<0 and deleted=0")->sum('amount'));
        $this->app->db->name('DataUser')->where(['id' => $uuid])->update([
            'rebate_total' => $total, 'rebate_used' => $count, 'rebate_lock' => $lockd,
        ]);
        return [$total, $count, $lockd];
    }

    /**
     * 同步刷新用户余额
     * @param int $uuid 用户UID
     * @param array $nots 排除的订单
     * @return array [total, count]
     * @throws \think\db\exception\DbException
     */
    public function syncBalance(int $uuid, array $nots = []): array
    {
        $total = abs($this->app->db->name('DataUserBalance')->where("uid='{$uuid}' and status=1 and amount>0 and deleted=0")->sum('amount'));
        $count = abs($this->app->db->name('DataUserBalance')->where("uid='{$uuid}' and status=1 and amount<0 and deleted=0")->sum('amount'));
        if (empty($nots)) {
            $this->app->db->name('DataUser')->where(['id' => $uuid])->update(['balance_total' => $total, 'balance_used' => $count]);
        } else {
            $count -= $this->app->db->name('DataUserBalance')->whereRaw("uid={$uuid}")->whereIn('code', $nots)->sum('amount');
        }
        return [$total, $count];
    }

    /**
     * 同步计算用户等级
     * @param integer $uid 指定用户UID
     * @param boolean $parent 同步计算上级
     * @param ?string $orderNo 升级触发订单
     * @return boolean
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function syncLevel(int $uid, bool $parent = true, ?string $orderNo = null): bool
    {
        $user = $this->app->db->name('DataUser')->where(['id' => $uid])->find();
        if (empty($user)) return true;
        // 刷新用户返利
        $this->syncRebate($uid);
        // 开始处理等级
        [$vipName, $vipCode] = ['普通用户', 0];
        // 统计历史数据
        $teamsDirect = $this->app->db->name('DataUser')->where(['pid1' => $uid])->where('vip_code>0')->count();
        $teamsIndirect = $this->app->db->name('DataUser')->where(['pid2' => $uid])->where('vip_code>0')->count();
        $teamsUsers = $this->app->db->name('DataUser')->where(['pid1|pid2' => $uid])->where('vip_code>0')->count();
        $orderAmount = $this->app->db->name('ShopOrder')->where("uid={$uid} and status>=4")->sum('amount_total');
        // 计算用户等级
        foreach ($this->app->db->name('DataUserUpgrade')->where(['status' => 1])->order('number desc')->cursor() as $item) {
            $l1 = empty($item['goods_vip_status']) || $user['buy_vip_entry'] > 0;
            $l2 = empty($item['teams_users_status']) || $item['teams_users_number'] <= $teamsUsers;
            $l3 = empty($item['order_amount_status']) || $item['order_amount_number'] <= $orderAmount;
            $l4 = empty($item['teams_direct_status']) || $item['teams_direct_number'] <= $teamsDirect;
            $l5 = empty($item['teams_indirect_status']) || $item['teams_indirect_number'] <= $teamsIndirect;
            if (
                ($item['upgrade_type'] == 0 && ($l1 || $l2 || $l3 || $l4 || $l5)) /* 满足任何条件可以等级 */
                ||
                ($item['upgrade_type'] == 1 && ($l1 && $l2 && $l3 && $l4 && $l5)) /* 满足所有条件可以等级 */
            ) {
                [$vipName, $vipCode] = [$item['name'], $item['number']];
                break;
            }
        }
        // 购买商品升级
        $query = $this->app->db->name('ShopOrderItem')->alias('b')->join('shop_order a', 'b.order_no=a.order_no');
        $tmpNumber = $query->whereRaw("a.uid={$uid} and a.payment_status=1 and a.status>=4 and b.vip_entry=1")->max('b.vip_code');
        if ($tmpNumber > $vipCode) {
            $map = ['status' => 1, 'number' => $tmpNumber];
            $upgrade = $this->app->db->name('DataUserUpgrade')->where($map)->find();
            if (!empty($upgrade)) [$vipName, $vipCode] = [$upgrade['name'], $upgrade['number']];
        } else {
            $orderNo = null;
        }
        // 统计订单金额
        $orderAmountTotal = $this->app->db->name('ShopOrder')->whereRaw("uid={$uid} and status>=4")->sum('amount_goods');
        $teamsAmountDirect = $this->app->db->name('ShopOrder')->whereRaw("puid1={$uid} and status>=4")->sum('amount_goods');
        $teamsAmountIndirect = $this->app->db->name('ShopOrder')->whereRaw("puid2={$uid} and status>=4")->sum('amount_goods');
        // 更新用户数据
        $data = [
            'vip_name'              => $vipName,
            'vip_code'              => $vipCode,
            'teams_users_total'     => $teamsUsers,
            'teams_users_direct'    => $teamsDirect,
            'teams_users_indirect'  => $teamsIndirect,
            'teams_amount_total'    => $teamsAmountDirect + $teamsAmountIndirect,
            'teams_amount_direct'   => $teamsAmountDirect,
            'teams_amount_indirect' => $teamsAmountIndirect,
            'order_amount_total'    => $orderAmountTotal,
        ];
        if (!empty($orderNo)) $data['vip_order'] = $orderNo;
        if ($data['vip_code'] !== $user['vip_code']) $data['vip_datetime'] = date('Y-m-d H:i:s');
        $this->app->db->name('DataUser')->where(['id' => $uid])->update($data);
        if ($user['vip_code'] < $vipCode) {
            // 用户升级事件
            $this->app->event->trigger('UserUpgradeLevel', [
                'uid' => $user['uid'], 'order_no' => $orderNo, 'vip_code_old' => $user['vip_code'], 'vip_code_new' => $vipCode,
            ]);
        }
        return ($parent && $user['pid2'] > 0) ? $this->syncLevel($user['pid2'], false) : true;
    }
}