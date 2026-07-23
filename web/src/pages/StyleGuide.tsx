// /style-guide — Design System v2.1 §9 definition of done:
// palette · every component variant with §8 states · toast+confirm via App.useApp()
// (proves the wrapper — no default blue) · line chart + heatmap on kaChartTheme ·
// TC sample at 1.8 line-height · mobile bottom sheet demo.
import { useState } from 'react';
import {
  App,
  Button,
  Checkbox,
  Divider,
  Flex,
  Input,
  Modal,
  Progress,
  Radio,
  Select,
  Steps,
  Switch,
  Table,
  Tabs,
  Tag,
  Typography,
} from 'antd';
import { Heatmap, Line } from '@ant-design/charts';
import { useTranslation } from 'react-i18next';
import { kaCategoryAccents, kaColors } from '../theme/theme';
import { kaChartDefaults, viridis } from '../theme/chartTheme';
import { BottomSheet } from '../components/mobile/BottomSheet';

const { Title, Paragraph, Text } = Typography;

const PALETTE = [
  ['background', kaColors.background],
  ['card', kaColors.card],
  ['gold', kaColors.gold],
  ['goldHover', kaColors.goldHover],
  ['muted', kaColors.muted],
  ['border', kaColors.border],
  ['borderStrong', kaColors.borderStrong],
  ['success', kaColors.success],
  ['warning', kaColors.warning],
  ['danger', kaColors.danger],
] as const;

const lineData = [
  { month: '2026-01', count: 12 },
  { month: '2026-02', count: 18 },
  { month: '2026-03', count: 26 },
  { month: '2026-04', count: 24 },
  { month: '2026-05', count: 35 },
  { month: '2026-06', count: 41 },
  { month: '2026-07', count: 48 },
];

const heatmapData = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].flatMap((day, d) =>
  Array.from({ length: 12 }, (_, h) => ({
    day,
    hour: `${h + 8}:00`,
    value: Math.round(20 + 60 * Math.abs(Math.sin(d * 1.3 + h * 0.7))),
  })),
);

export function StyleGuide() {
  const { t } = useTranslation();
  const { message, modal } = App.useApp();
  const [modalOpen, setModalOpen] = useState(false);
  const [sheetOpen, setSheetOpen] = useState(false);

  return (
    <Typography style={{ maxWidth: 960 }}>
      <Title level={1}>{t('styleGuide.title')}</Title>
      <Paragraph type="secondary">{t('styleGuide.subtitle')}</Paragraph>

      <Title level={2}>{t('styleGuide.palette')}</Title>
      <Flex wrap gap={12}>
        {PALETTE.map(([name, hex]) => (
          <div key={name} className="ka-swatch">
            <div className="ka-swatch-chip" style={{ background: hex }} />
            <Text code>{hex}</Text>
            <Text type="secondary">{name}</Text>
          </div>
        ))}
        {Object.entries(kaCategoryAccents).map(([name, hex]) => (
          <div key={name} className="ka-swatch">
            <div className="ka-swatch-chip" style={{ background: hex }} />
            <Text code>{hex}</Text>
            <Text type="secondary">{name}</Text>
          </div>
        ))}
      </Flex>

      <Divider />
      <Title level={2}>{t('styleGuide.buttons')}</Title>
      <Flex wrap gap={12} align="center">
        <Button type="primary">{t('styleGuide.buttonPrimary')}</Button>
        <Button type="primary" className="ka-cta">
          {t('styleGuide.buttonGoldCta')}
        </Button>
        <Button>{t('styleGuide.buttonDefault')}</Button>
        <Button type="dashed">{t('styleGuide.buttonDashed')}</Button>
        <Button type="text">{t('styleGuide.buttonText')}</Button>
        <Button type="link">{t('styleGuide.buttonLink')}</Button>
        <Button danger type="primary">
          {t('styleGuide.buttonDanger')}
        </Button>
        <Button disabled>{t('styleGuide.buttonDisabled')}</Button>
        <Button type="primary" loading>
          {t('styleGuide.buttonLoading')}
        </Button>
      </Flex>

      <Divider />
      <Title level={2}>{t('styleGuide.tags')}</Title>
      <Flex wrap gap={8}>
        <Tag color="success">{t('styleGuide.tagActive')}</Tag>
        <Tag color="processing">{t('styleGuide.tagCompleted')}</Tag>
        <Tag color="warning">{t('styleGuide.tagPending')}</Tag>
        <Tag>{t('styleGuide.tagDraft')}</Tag>
        <Tag color="error">{t('styleGuide.tagFailed')}</Tag>
      </Flex>

      <Divider />
      <Title level={2}>{t('styleGuide.tabs')}</Title>
      <Tabs
        items={[
          { key: '1', label: t('styleGuide.tabOne'), children: t('styleGuide.tabOne') },
          { key: '2', label: t('styleGuide.tabTwo'), children: t('styleGuide.tabTwo') },
          { key: '3', label: t('styleGuide.tabThree'), children: t('styleGuide.tabThree') },
        ]}
      />

      <Divider />
      <Title level={2}>{t('styleGuide.table')}</Title>
      <Table
        pagination={false}
        columns={[
          { title: t('styleGuide.tableName'), dataIndex: 'name' },
          {
            title: t('styleGuide.tableAmount'),
            dataIndex: 'amount',
            align: 'right',
            className: 'ka-tabular-nums',
          },
          {
            title: t('styleGuide.tableStatus'),
            dataIndex: 'status',
            render: (s: string) =>
              s === 'active' ? (
                <Tag color="success">{t('styleGuide.tagActive')}</Tag>
              ) : (
                <Tag color="warning">{t('styleGuide.tagPending')}</Tag>
              ),
          },
        ]}
        dataSource={[
          { key: 1, name: 'STEM on Car 2026', amount: 'HK$12,800.00', status: 'active' },
          { key: 2, name: 'Cambridge English Online', amount: 'HK$9,600.00', status: 'pending' },
          { key: 3, name: 'Creative Arts Studio', amount: 'HK$7,400.00', status: 'active' },
        ]}
      />

      <Divider />
      <Title level={2}>{t('styleGuide.inputs')}</Title>
      <Flex vertical gap={16} style={{ maxWidth: 420 }}>
        <div>
          <label className="ka-label" htmlFor="sg-input">
            {t('styleGuide.inputLabel')}
          </label>
          <Input id="sg-input" placeholder={t('styleGuide.inputPlaceholder')} />
        </div>
        <div>
          <label className="ka-label" htmlFor="sg-select">
            {t('styleGuide.selectLabel')}
          </label>
          <Select
            id="sg-select"
            placeholder={t('styleGuide.selectPlaceholder')}
            style={{ width: '100%' }}
            options={[
              { value: 'sc5', label: 'STEM on Car 2026' },
              { value: 'sc1', label: 'Cambridge English Online' },
            ]}
          />
        </div>
        <Checkbox>{t('styleGuide.checkboxLabel')}</Checkbox>
        <Flex gap={12} align="center">
          <Switch defaultChecked aria-label={t('styleGuide.switchLabel')} />
          <Text>{t('styleGuide.switchLabel')}</Text>
        </Flex>
        <Radio.Group
          defaultValue="a"
          options={[
            { value: 'a', label: t('styleGuide.radioA') },
            { value: 'b', label: t('styleGuide.radioB') },
          ]}
        />
      </Flex>

      <Divider />
      <Title level={2}>{t('styleGuide.steps')}</Title>
      <Steps
        current={2}
        items={[
          { title: t('styleGuide.stagePlan') },
          { title: t('styleGuide.stageDesign') },
          { title: t('styleGuide.stageLearn') },
          { title: t('styleGuide.stagePitch') },
          { title: t('styleGuide.stageLaunch') },
        ]}
      />

      <Divider />
      <Title level={2}>{t('styleGuide.progress')}</Title>
      <Flex gap={24} align="center">
        <Progress type="circle" percent={68} size={80} />
        <Progress percent={68} style={{ maxWidth: 320 }} />
      </Flex>

      <Divider />
      <Title level={2}>{t('styleGuide.modal')}</Title>
      <Button onClick={() => setModalOpen(true)}>{t('styleGuide.openModal')}</Button>
      <Modal
        title={t('styleGuide.modalTitle')}
        open={modalOpen}
        onOk={() => setModalOpen(false)}
        onCancel={() => setModalOpen(false)}
        okText={t('styleGuide.ok')}
        cancelText={t('styleGuide.cancel')}
        width={420}
      >
        {t('styleGuide.modalBody')}
      </Modal>

      <Divider />
      <Title level={2}>{t('styleGuide.feedback')}</Title>
      <Flex gap={12}>
        <Button
          type="primary"
          onClick={() => void message.success(t('styleGuide.toastMessage'))}
        >
          {t('styleGuide.fireToast')}
        </Button>
        <Button
          onClick={() =>
            modal.confirm({
              title: t('styleGuide.confirmTitle'),
              content: t('styleGuide.confirmBody'),
              okText: t('styleGuide.ok'),
              cancelText: t('styleGuide.cancel'),
              onOk: () => void message.info(t('styleGuide.confirmed')),
            })
          }
        >
          {t('styleGuide.fireConfirm')}
        </Button>
      </Flex>

      <Divider />
      <Title level={2}>{t('styleGuide.charts')}</Title>
      <Title level={3}>{t('styleGuide.chartLine')}</Title>
      <Line
        {...kaChartDefaults}
        data={lineData}
        xField="month"
        yField="count"
        height={240}
        style={{ stroke: kaColors.gold, lineWidth: 2 }}
      />
      <Title level={3}>{t('styleGuide.chartHeatmap')}</Title>
      <Heatmap
        {...kaChartDefaults}
        data={heatmapData}
        xField="hour"
        yField="day"
        colorField="value"
        mark="cell"
        height={280}
        scale={{ color: { range: [...viridis] } }}
      />

      <Divider />
      <Title level={2}>{t('styleGuide.typography')}</Title>
      <Paragraph lang="zh-Hant-HK" className="ka-tc-sample">
        {t('styleGuide.tcSample')}
      </Paragraph>

      <Divider />
      <Title level={2}>{t('styleGuide.mobile')}</Title>
      <Button onClick={() => setSheetOpen(true)}>{t('styleGuide.openSheet')}</Button>
      <BottomSheet
        open={sheetOpen}
        onClose={() => setSheetOpen(false)}
        title={t('styleGuide.sheetTitle')}
      >
        <Paragraph>{t('styleGuide.sheetBody')}</Paragraph>
      </BottomSheet>
    </Typography>
  );
}
