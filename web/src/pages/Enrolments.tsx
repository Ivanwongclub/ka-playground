// S04A step 6: E5 timeline reshaped for team-based capacity — the pool is a
// state; each enrolment renders its journey with the current stage highlighted.
import { useEffect, useState } from 'react';
import { Card, Space, Steps, Table, Tag, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { authFetch } from '../auth/session';

const { Title, Paragraph } = Typography;

interface Row {
  id: string;
  programme_id: number;
  student_id: number;
  acting_guardian_id: number;
  status: string;
  created_at: string;
}

const JOURNEY = ['submitted', 'pending_consent', 'in_pool', 'teamed', 'confirmed', 'active', 'completed'];
const TERMINAL_BAD = ['withdrawn', 'released'];

export function Enrolments() {
  const { t } = useTranslation();
  const [rows, setRows] = useState<Row[]>([]);

  useEffect(() => {
    void authFetch('/api/enrolments').then(async (r) => r.ok && setRows(((await r.json()) as { data: Row[] }).data));
  }, []);

  return (
    <Space direction="vertical" size="large" style={{ width: '100%' }}>
      <Card>
        <Title level={3}>{t('enrol.listTitle')}</Title>
        <Paragraph type="secondary">{t('enrol.listCaption')}</Paragraph>
        <Table<Row>
          rowKey="id"
          dataSource={rows}
          pagination={false}
          columns={[
            { title: t('enrol.programme'), dataIndex: 'programme_id' },
            { title: t('enrol.student'), dataIndex: 'student_id' },
            {
              title: '', dataIndex: 'status',
              render: (s: string) => (
                <Tag color={TERMINAL_BAD.includes(s) ? 'red' : s === 'completed' ? 'green' : 'gold'}>
                  {t(`enrol.status.${s}`)}
                </Tag>
              ),
            },
          ]}
          expandable={{
            expandedRowRender: (row) => TERMINAL_BAD.includes(row.status)
              ? <Tag color="red">{t(`enrol.status.${row.status}`)}</Tag>
              : (
                <Steps
                  size="small"
                  current={JOURNEY.indexOf(row.status)}
                  items={JOURNEY.map((s) => ({ title: t(`enrol.status.${s}`) }))}
                />
              ),
          }}
        />
      </Card>
    </Space>
  );
}
